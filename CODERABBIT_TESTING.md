# CodeRabbit Testing Configuration

This document outlines the CodeRabbit configuration for testing the Performance Optimisation WordPress plugin.

## Configuration Files

### 1. `.coderabbit.yaml`
Main configuration file that defines:
- Review profiles and settings
- Path-specific instructions for different code areas
- WordPress-specific security and performance checks
- Custom rules for service container usage
- Testing requirements and coverage targets

### 2. `phpunit.xml`
PHPUnit configuration for automated testing:
- Test suites organization
- Code coverage reporting
- WordPress test environment setup

### 3. `.github/workflows/test.yml`
GitHub Actions workflow for CI/CD:
- Multi-version PHP and WordPress testing
- Security scanning
- Performance benchmarking
- Code quality checks

## Key Testing Areas

### Security Testing
CodeRabbit will focus on:
- SQL injection prevention
- XSS vulnerability detection
- CSRF protection verification
- File inclusion security
- Input sanitization and validation
- Capability and permission checks

### Performance Testing
- Database query optimization
- File operation efficiency
- Memory usage monitoring
- Caching strategy validation
- Service container performance

### WordPress Standards
- Coding standards compliance (WordPress)
- Proper hook usage
- Internationalization
- Admin interface security
- REST API implementation

## Critical Code Paths

CodeRabbit will pay special attention to:

1. **Core Bootstrap** (`includes/Core/Bootstrap/Plugin.php`)
   - Plugin initialization
   - Service registration
   - Hook setup

2. **Service Container** (`includes/Core/ServiceContainer.php`)
   - Dependency injection
   - Service resolution
   - Memory management

3. **Cache Service** (`includes/Services/CacheService.php`)
   - Cache operations security
   - Performance optimization
   - Data integrity

4. **Admin Interface** (`includes/Admin/Admin.php`)
   - User permission checks
   - Form security
   - Menu registration

5. **File System Operations** (`includes/Utils/FileSystemUtil.php`)
   - File security
   - Path traversal prevention
   - Permission handling

## Testing Commands

### Local Testing
```bash
# Install dependencies
composer install

# Run PHP CodeSniffer
vendor/bin/phpcs --standard=WordPress includes/

# Run PHPStan
vendor/bin/phpstan analyse --level=8 includes/

# Run Psalm
vendor/bin/psalm

# Run PHPUnit tests
vendor/bin/phpunit

# Run security scan
composer run security-check
```

### Performance Testing
```bash
# Memory usage test
php tests/performance/memory-usage.php

# Load time benchmark
php tests/performance/load-time.php

# Cache performance test
php tests/performance/cache-benchmark.php
```

## CodeRabbit Review Focus

### High Priority Issues
- Security vulnerabilities
- Performance bottlenecks
- WordPress standards violations
- Service container issues
- Database query problems

### Medium Priority Issues
- Code organization
- Documentation quality
- Error handling
- Test coverage
- Memory optimization

### Low Priority Issues
- Code style consistency
- Comment quality
- Variable naming
- Function organization

## Custom Rules

### Service Container Usage
- Proper dependency injection
- Circular dependency detection
- Service registration validation

### WordPress Hooks
- Correct hook usage
- Priority settings
- Hook documentation

### Cache Operations
- Security validation
- Performance optimization
- Data integrity checks

### File Operations
- Security validation
- Permission checks
- Error handling

## Expected Coverage

- **Minimum Coverage**: 70%
- **Target Coverage**: 85%
- **Critical Functions**: 95%

## Performance Benchmarks

- **Plugin Load Time**: < 50ms
- **Service Resolution**: < 5ms
- **Cache Operations**: < 1ms
- **Admin Page Load**: < 200ms

## Security Requirements

- All user inputs must be sanitized
- All database queries must use prepared statements
- All file operations must validate paths
- All admin functions must check capabilities
- All forms must use nonces

## Integration Testing

CodeRabbit will verify:
- WordPress multisite compatibility
- Plugin conflict resolution
- Theme compatibility
- Third-party plugin integration
- Database migration safety

## Reporting

CodeRabbit will provide:
- Detailed security analysis
- Performance recommendations
- Code quality metrics
- Test coverage reports
- Compliance verification

## Continuous Monitoring

The configuration enables:
- Automated security scanning
- Performance regression detection
- Code quality monitoring
- Dependency vulnerability checks
- WordPress compatibility verification
