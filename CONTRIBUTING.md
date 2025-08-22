# Contributing to Laravel PDF Viewer Package

Thank you for considering contributing to the Laravel PDF Viewer Package! We welcome contributions from the community and appreciate your help in making this package better.

## Code of Conduct

This project adheres to a code of conduct. By participating, you are expected to uphold this code. Please be respectful and professional in all interactions.

## How Can I Contribute?

### Reporting Bugs

Before creating bug reports, please check the existing issues to avoid duplicates. When you create a bug report, please include as much detail as possible:

- Use the bug report template
- Include PHP, Laravel, and package versions
- Provide steps to reproduce the issue
- Include relevant configuration and error messages
- Add sample PDFs or test cases if applicable

### Suggesting Enhancements

We welcome feature requests and enhancement suggestions:

- Use the feature request template
- Explain the use case and benefits
- Consider implementation complexity and backward compatibility
- Provide examples or mockups when possible

### Pull Requests

We actively welcome your pull requests:

1. Fork the repository
2. Create a feature branch from `develop` 
3. Make your changes
4. Add or update tests
5. Ensure all tests pass
6. Update documentation if needed
7. Submit a pull request

## Development Setup

### Prerequisites

- PHP 8.1 or higher
- Composer
- Laravel 11.9+
- MySQL 5.7+ or SQLite for testing
- Redis (for caching tests)

### Installation

1. **Fork and clone the repository:**
```bash
git clone https://github.com/your-username/laravel-pdf-viewer.git
cd laravel-pdf-viewer
```

2. **Install dependencies:**
```bash
composer install
```

3. **Set up testing environment:**
```bash
# Copy PHPUnit configuration
cp phpunit.xml.dist phpunit.xml

# Create test database (SQLite)
touch database/database.sqlite
```

4. **Run tests to ensure everything works:**
```bash
./vendor/bin/phpunit
```

### Testing

We maintain comprehensive test coverage. Please ensure:

1. **Write tests for new features:**
   - Unit tests for services and jobs
   - Feature tests for API endpoints
   - Integration tests for complex workflows

2. **Update existing tests when modifying functionality**

3. **Ensure all tests pass:**
```bash
# Run all tests
./vendor/bin/phpunit

# Run specific test suites
./vendor/bin/phpunit tests/Unit
./vendor/bin/phpunit tests/Feature

# Run with coverage
./vendor/bin/phpunit --coverage-html coverage
```

4. **Test with sample PDFs:**
   - Place test PDFs in `SamplePDF/` directory
   - Use aviation-related PDFs when possible for consistency
   - Test with various PDF sizes and complexities

### Code Style

We follow PSR-12 coding standards and Laravel conventions:

1. **Use consistent formatting:**
   - 4 spaces for indentation
   - Unix line endings (LF)
   - No trailing whitespace

2. **Follow Laravel conventions:**
   - Use Laravel's naming conventions for classes, methods, and variables
   - Follow Laravel's directory structure
   - Use Laravel's helper functions when appropriate

3. **Write clear, self-documenting code:**
   - Use descriptive variable and method names
   - Add docblocks for public methods
   - Include type hints for parameters and return types

### Documentation

When contributing, please update relevant documentation:

1. **Code Documentation:**
   - Add docblocks for new public methods
   - Update existing docblocks when changing method signatures
   - Use type hints consistently

2. **README Updates:**
   - Update installation or usage instructions if needed
   - Add new configuration options
   - Update examples for new features

3. **API Documentation:**
   - Update API documentation for new endpoints
   - Include request/response examples
   - Document new parameters or response fields

4. **Changelog:**
   - Add entries to CHANGELOG.md for new features or bug fixes
   - Follow the established format
   - Include upgrade instructions for breaking changes

## Architecture Guidelines

### SOLID Principles

The package follows SOLID design principles:

1. **Single Responsibility:** Each class has one reason to change
2. **Open/Closed:** Classes are open for extension, closed for modification
3. **Liskov Substitution:** Derived classes must be substitutable for base classes
4. **Interface Segregation:** Clients shouldn't depend on unused interfaces
5. **Dependency Inversion:** Depend on abstractions, not concretions

### Service Architecture

When adding new services:

1. **Create interfaces first:** Define contracts in `src/Contracts/`
2. **Implement services:** Create implementations in `src/Services/`
3. **Register in service provider:** Bind interfaces to implementations
4. **Add comprehensive tests:** Both unit and integration tests

### Job Architecture

For queue jobs:

1. **Keep jobs focused:** Each job should have a single responsibility
2. **Handle failures gracefully:** Implement proper error handling and retries
3. **Make jobs serializable:** Ensure all job data can be serialized
4. **Test job processing:** Include tests for job execution and failure scenarios

### Database Considerations

When modifying database schema:

1. **Create migrations:** Always use Laravel migrations for schema changes
2. **Consider performance:** Add appropriate indexes for queries
3. **Maintain compatibility:** Avoid breaking changes to existing schema
4. **Update factories:** Keep test data factories in sync with schema

## Contribution Workflow

### Branch Strategy

- `main` - Stable releases only
- `develop` - Development branch, merge PRs here
- `feature/feature-name` - Feature branches
- `bugfix/issue-description` - Bug fix branches
- `hotfix/critical-issue` - Emergency fixes for main

### Pull Request Process

1. **Create descriptive PR title and description:**
   - Explain what the PR does and why
   - Reference related issues
   - Include testing instructions if needed

2. **Ensure CI passes:**
   - All tests must pass
   - Code style checks must pass
   - No breaking changes without discussion

3. **Request review:**
   - Tag relevant maintainers
   - Address feedback promptly
   - Update PR based on review comments

4. **Squash and merge:**
   - We prefer squash merges to keep history clean
   - Write clear commit messages
   - Include issue references in commit messages

### Release Process

Releases follow semantic versioning:

- **Major (1.0.0):** Breaking changes
- **Minor (1.1.0):** New features, backward compatible
- **Patch (1.0.1):** Bug fixes, backward compatible

## Common Contribution Areas

### High-Impact Areas

1. **Performance improvements:**
   - PDF processing optimization
   - Database query optimization
   - Caching strategies

2. **New search features:**
   - Advanced search filters
   - Search result ranking
   - Search analytics

3. **Enhanced job processing:**
   - Better error handling
   - Progress tracking improvements
   - Job prioritization

4. **Testing improvements:**
   - More comprehensive test coverage
   - Performance testing
   - Integration testing

### Documentation Improvements

1. **Usage examples:**
   - Real-world integration examples
   - Framework-specific guides
   - Performance tuning guides

2. **API documentation:**
   - More detailed parameter descriptions
   - Additional code examples
   - Error handling examples

### Tools and Utilities

1. **Development tools:**
   - Better testing utilities
   - Development environment setup
   - Debugging helpers

2. **Monitoring tools:**
   - Health check endpoints
   - Performance monitoring
   - Error tracking integration

## Getting Help

If you need help with contributing:

1. **Check existing documentation:**
   - README.md
   - API documentation
   - Code comments

2. **Search existing issues:**
   - Look for similar questions or problems
   - Check closed issues for solutions

3. **Create a discussion:**
   - Use GitHub Discussions for questions
   - Ask for guidance before starting large changes
   - Discuss architectural decisions

4. **Contact maintainers:**
   - Tag maintainers in issues or PRs
   - Be patient - we're volunteers
   - Provide context and details

## Recognition

We appreciate all contributions and will recognize contributors:

- Contributors are listed in release notes
- Major contributors may be added to README
- We'll provide references and recommendations when requested

Thank you for contributing to the Laravel PDF Viewer Package!