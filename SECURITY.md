# Security Policy

## Supported Versions

We actively support and provide security updates for the following versions of the Laravel PDF Viewer Package:

| Version | Supported          |
| ------- | ------------------ |
| 1.0.x   | :white_check_mark: |

## Reporting a Vulnerability

If you discover a security vulnerability in the Laravel PDF Viewer Package, please report it responsibly by following these guidelines:

### How to Report

**Do NOT** create a public GitHub issue for security vulnerabilities.

Instead, please email us directly at: **security@shakewellagency.com**

### What to Include

Please include the following information in your security report:

1. **Description of the vulnerability**
   - Clear explanation of the security issue
   - Potential impact and severity assessment

2. **Steps to reproduce**
   - Detailed steps to demonstrate the vulnerability
   - Include any necessary configuration or setup

3. **Proof of concept**
   - Code snippets, screenshots, or examples if applicable
   - Please be responsible and don't exploit the vulnerability

4. **Suggested fix** (if you have one)
   - Any ideas for how the vulnerability could be addressed
   - This is optional but helpful

5. **Environment details**
   - Package version
   - Laravel version
   - PHP version
   - Database type and version

### Response Timeline

We take security vulnerabilities seriously and will respond according to the following timeline:

- **Initial Response**: Within 48 hours of receiving your report
- **Status Update**: Within 7 days with our assessment and timeline
- **Resolution**: Security fixes will be prioritized and released as soon as possible
- **Disclosure**: Public disclosure only after a fix has been released and users have had time to update

### Security Best Practices

When using the Laravel PDF Viewer Package, please follow these security recommendations:

#### File Upload Security
- Always validate file types and sizes
- Use the package's built-in validation rules
- Store uploaded files outside the web root
- Configure proper file permissions

#### Authentication & Authorization
- Always require authentication for API endpoints
- Implement proper authorization checks
- Use rate limiting on upload and search endpoints
- Validate user permissions before allowing document access

#### Document Hash Security
- Never expose internal document IDs
- Always use the secure hash-based identification system
- Implement proper access controls based on user permissions

#### Database Security
- Use prepared statements (Laravel handles this by default)
- Regularly update database software
- Implement proper database user permissions
- Consider encrypting sensitive metadata fields

#### Cache Security
- Use Redis authentication when possible
- Implement cache key prefixing to avoid collisions
- Regularly invalidate caches for updated documents
- Consider encrypting cached sensitive data

#### Queue Security
- Secure queue connections with authentication
- Monitor queue workers for suspicious activity
- Implement proper job failure handling
- Avoid storing sensitive data in job payloads

#### Configuration Security
```php
// Example secure configuration
'security' => [
    'hash_algorithm' => 'sha256', // Use strong hashing
    'max_file_size' => 104857600, // 100MB limit
    'allowed_mime_types' => ['application/pdf'],
    'require_authentication' => true,
    'rate_limiting' => [
        'upload' => '10,1', // 10 uploads per minute
        'search' => '60,1', // 60 searches per minute
    ],
],
```

### Known Security Considerations

#### PDF Processing Risks
- Large PDFs can consume significant memory and processing time
- Malicious PDFs could potentially exploit PDF parsing libraries
- Text extraction from PDFs may reveal sensitive information

#### Mitigation Strategies
- Implement resource limits and timeouts
- Use sandboxed environments for PDF processing
- Regularly update PDF processing dependencies
- Monitor system resources during processing

#### Search Security
- Full-text search may expose sensitive document content
- Implement proper access controls on search results
- Consider search query logging and monitoring
- Validate search parameters to prevent injection attacks

### Responsible Disclosure

We follow responsible disclosure practices:

1. **Private reporting**: Security issues are reported privately first
2. **Fix development**: We develop and test fixes before public disclosure
3. **User notification**: We notify users of security updates through release notes
4. **Public disclosure**: Details are shared publicly only after fixes are available

### Security Updates

Security updates will be:
- Released as patch versions (e.g., 1.0.1, 1.0.2)
- Clearly marked in release notes and CHANGELOG.md
- Communicated through our standard release channels

### Recognition

We appreciate security researchers who help improve the package security. With your permission, we'll acknowledge your contribution in:
- Release notes for security fixes
- Security acknowledgments section (if you prefer)
- Our security hall of fame (if maintained)

### Contact

For security-related questions or concerns:
- **Email**: security@shakewellagency.com
- **Response time**: We aim to respond within 48 hours
- **Encryption**: PGP key available upon request

Thank you for helping keep the Laravel PDF Viewer Package secure!