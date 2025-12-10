# Rollback Strategy & Feature Kill Switch

This document outlines procedures for safely disabling, rolling back, and re-enabling the TOC/Outline and Link extraction features in production environments.

## Table of Contents

1. [Feature Kill Switches](#feature-kill-switches)
2. [Emergency Disable Procedure](#emergency-disable-procedure)
3. [Database Rollback](#database-rollback)
4. [Monitoring & Detection](#monitoring--detection)
5. [Recovery Procedures](#recovery-procedures)
6. [Testing Rollback](#testing-rollback)

---

## Feature Kill Switches

### Environment-Based Kill Switches

The package provides environment variables that act as kill switches for each feature. These can be toggled without code deployment.

| Feature | Kill Switch | Default |
|---------|-------------|---------|
| TOC/Outline Extraction | `PDF_VIEWER_OUTLINE_ENABLED` | `true` |
| Link Extraction | `PDF_VIEWER_LINKS_ENABLED` | `true` |
| All Processing | `PDF_VIEWER_PROCESSING_ENABLED` | `true` |

### How Kill Switches Work

When a feature is disabled:

1. **New uploads**: TOC/Link extraction is skipped during document processing
2. **Backfill commands**: Will not process documents for disabled features
3. **API endpoints**: Continue to work but return empty data for disabled features
4. **Existing data**: Remains in database, unaffected

### Immediate Disable (No Restart Required)

For Laravel Vapor or environments with dynamic config:

```bash
# Using Laravel Vapor
vapor env:set PDF_VIEWER_OUTLINE_ENABLED=false --environment=production
vapor env:set PDF_VIEWER_LINKS_ENABLED=false --environment=production
```

For traditional deployments:

```bash
# Update .env file
echo "PDF_VIEWER_OUTLINE_ENABLED=false" >> .env
echo "PDF_VIEWER_LINKS_ENABLED=false" >> .env

# Clear config cache
php artisan config:clear
php artisan config:cache
```

---

## Emergency Disable Procedure

### Level 1: Feature Isolation (Soft Disable)

Use when: Feature is causing errors but not system-critical.

```bash
# 1. Disable extraction features
export PDF_VIEWER_OUTLINE_ENABLED=false
export PDF_VIEWER_LINKS_ENABLED=false

# 2. Clear config cache (if applicable)
php artisan config:clear

# 3. Restart queue workers to pick up new config
php artisan queue:restart
```

**Impact**: New documents won't have TOC/links extracted. Existing data remains available.

### Level 2: Queue Pause (Stop Processing)

Use when: Extraction jobs are consuming excessive resources or causing system instability.

```bash
# 1. Pause specific queues
php artisan queue:pause pdf-processing
php artisan queue:pause pdf-pages

# 2. Monitor queue backlog
php artisan queue:monitor redis:pdf-processing,pdf-pages --max=1000
```

**Impact**: All document processing pauses. Resume when fixed.

### Level 3: Database Table Truncation (Data Reset)

Use when: Corrupted data is causing application errors.

```sql
-- WARNING: This deletes all extracted data
-- Make a backup first!

-- Backup tables
CREATE TABLE pdf_document_outlines_backup AS SELECT * FROM pdf_document_outlines;
CREATE TABLE pdf_document_links_backup AS SELECT * FROM pdf_document_links;

-- Truncate tables
TRUNCATE TABLE pdf_document_links;
TRUNCATE TABLE pdf_document_outlines;
```

**Impact**: All TOC/link data removed. Must re-run backfill after fixing issues.

### Level 4: Migration Rollback (Schema Removal)

Use when: Feature needs complete removal.

```bash
# WARNING: This removes the database tables entirely

# Rollback link table migration
php artisan migrate:rollback --path=database/migrations/2025_12_09_000002_create_pdf_document_links_table.php

# Rollback outline table migration
php artisan migrate:rollback --path=database/migrations/2025_12_09_000001_create_pdf_document_outlines_table.php
```

**Impact**: Tables are dropped. Data is lost. Must re-migrate and re-run backfill to restore.

---

## Database Rollback

### Safe Rollback Script

Create a backup-and-rollback script for automated recovery:

```bash
#!/bin/bash
# save as: scripts/rollback-toc-links.sh

set -e

BACKUP_DIR="/tmp/pdf-viewer-backup-$(date +%Y%m%d_%H%M%S)"
mkdir -p "$BACKUP_DIR"

echo "Creating backups..."

# MySQL backup
mysqldump -u$DB_USERNAME -p$DB_PASSWORD $DB_DATABASE pdf_document_outlines > "$BACKUP_DIR/outlines.sql"
mysqldump -u$DB_USERNAME -p$DB_PASSWORD $DB_DATABASE pdf_document_links > "$BACKUP_DIR/links.sql"

echo "Backups saved to: $BACKUP_DIR"

# Confirm before proceeding
read -p "Proceed with truncation? (yes/no): " confirm
if [ "$confirm" != "yes" ]; then
    echo "Aborted."
    exit 1
fi

# Truncate tables
mysql -u$DB_USERNAME -p$DB_PASSWORD $DB_DATABASE -e "SET FOREIGN_KEY_CHECKS=0; TRUNCATE TABLE pdf_document_links; TRUNCATE TABLE pdf_document_outlines; SET FOREIGN_KEY_CHECKS=1;"

echo "Tables truncated successfully."
echo "To restore: mysql -u\$DB_USERNAME -p\$DB_PASSWORD \$DB_DATABASE < $BACKUP_DIR/outlines.sql"
```

### Restore from Backup

```bash
# Restore from mysqldump backup
mysql -u$DB_USERNAME -p$DB_PASSWORD $DB_DATABASE < /path/to/backup/outlines.sql
mysql -u$DB_USERNAME -p$DB_PASSWORD $DB_DATABASE < /path/to/backup/links.sql
```

---

## Monitoring & Detection

### Key Metrics to Watch

| Metric | Warning Threshold | Critical Threshold | Action |
|--------|------------------|-------------------|--------|
| Extraction job failures | > 5% | > 20% | Investigate logs |
| Queue backlog size | > 1000 | > 5000 | Scale workers |
| Average job duration | > 60s | > 180s | Check resources |
| Memory usage | > 80% | > 95% | Reduce batch size |

### Health Check Command

```bash
# Run system health check
php artisan pdf-viewer:monitor-health

# Expected output:
# ✓ Database connection: OK
# ✓ Outline table: 12,345 records
# ✓ Links table: 45,678 records
# ✓ Queue status: 5 pending jobs
# ✓ Last processed: 2 minutes ago
```

### Log Monitoring

Watch for these error patterns:

```bash
# Monitor extraction failures
tail -f storage/logs/laravel.log | grep -E "(OutlineExtractor|LinkExtractor|extraction failed)"

# Monitor queue failures
tail -f storage/logs/laravel.log | grep -E "(ProcessDocumentJob.*failed|ExtractPageJob.*failed)"
```

### Automated Alerting

Configure Laravel's exception handler to alert on extraction failures:

```php
// app/Exceptions/Handler.php
protected function context(): array
{
    return array_filter([
        'pdf_outline_enabled' => config('pdf-viewer.extraction.outline_enabled'),
        'pdf_links_enabled' => config('pdf-viewer.extraction.links_enabled'),
    ]);
}
```

---

## Recovery Procedures

### After Feature Disable

1. **Identify root cause**: Check logs for extraction failures
2. **Fix issue**: Deploy code fix or adjust configuration
3. **Test**: Process a single document to verify fix
4. **Re-enable**: Toggle feature back on
5. **Backfill**: Process documents that were uploaded while feature was disabled

### Re-enable Features

```bash
# 1. Re-enable features
export PDF_VIEWER_OUTLINE_ENABLED=true
export PDF_VIEWER_LINKS_ENABLED=true

# 2. Clear and recache config
php artisan config:clear
php artisan config:cache

# 3. Restart queue workers
php artisan queue:restart

# 4. Backfill documents processed during disable period
php artisan pdf-viewer:backfill-metadata --all --queue --status=completed
```

### After Data Truncation

```bash
# Full backfill after data reset
php artisan pdf-viewer:backfill-metadata --all --queue --batch-size=25 --force

# Monitor progress
watch -n 5 'php artisan queue:monitor redis:pdf-processing'
```

### After Migration Rollback

```bash
# 1. Re-run migrations
php artisan migrate

# 2. Verify tables exist
php artisan tinker --execute="echo Schema::hasTable('pdf_document_outlines') ? 'OK' : 'MISSING';"
php artisan tinker --execute="echo Schema::hasTable('pdf_document_links') ? 'OK' : 'MISSING';"

# 3. Full backfill
php artisan pdf-viewer:backfill-metadata --all --queue
```

---

## Testing Rollback

### Pre-Deployment Checklist

Before deploying the TOC/Link extraction feature to production:

- [ ] Test kill switch toggles in staging
- [ ] Verify API endpoints return gracefully when feature disabled
- [ ] Test database rollback procedure
- [ ] Document current backup schedule
- [ ] Ensure monitoring is configured
- [ ] Have rollback commands ready

### Rollback Test Script

Run this in staging to verify rollback procedures work:

```bash
#!/bin/bash
# test-rollback.sh

echo "=== Testing Feature Kill Switches ==="

# Test 1: Disable outline extraction
echo "1. Disabling outline extraction..."
export PDF_VIEWER_OUTLINE_ENABLED=false
php artisan config:clear

# Verify API returns empty outline
RESPONSE=$(curl -s http://localhost/api/pdf-viewer/test-doc/outline)
if [ "$RESPONSE" == "[]" ] || [ "$RESPONSE" == '{"data":[]}' ]; then
    echo "   ✓ Outline API returns empty when disabled"
else
    echo "   ✗ Outline API should return empty"
fi

# Test 2: Re-enable
echo "2. Re-enabling outline extraction..."
export PDF_VIEWER_OUTLINE_ENABLED=true
php artisan config:clear

echo "=== Kill Switch Tests Complete ==="
```

### Validation Queries

```sql
-- Check data integrity after rollback/restore
SELECT
    'outlines' as table_name,
    COUNT(*) as total_records,
    COUNT(DISTINCT pdf_document_id) as unique_documents
FROM pdf_document_outlines

UNION ALL

SELECT
    'links' as table_name,
    COUNT(*) as total_records,
    COUNT(DISTINCT pdf_document_id) as unique_documents
FROM pdf_document_links;
```

---

## Quick Reference Card

### Emergency Commands

```bash
# Disable all extraction immediately
export PDF_VIEWER_OUTLINE_ENABLED=false
export PDF_VIEWER_LINKS_ENABLED=false
php artisan config:clear
php artisan queue:restart

# Pause all processing
php artisan queue:pause pdf-processing
php artisan queue:pause pdf-pages

# Check queue status
php artisan queue:monitor

# Resume processing
php artisan queue:resume pdf-processing
php artisan queue:resume pdf-pages
```

### Recovery Commands

```bash
# Re-enable and backfill
export PDF_VIEWER_OUTLINE_ENABLED=true
export PDF_VIEWER_LINKS_ENABLED=true
php artisan config:clear
php artisan queue:restart
php artisan pdf-viewer:backfill-metadata --all --queue
```

---

## Contacts

In case of emergency:
- **Package Maintainer**: [Your Team]
- **DevOps On-Call**: [Contact]
- **Database Admin**: [Contact]
