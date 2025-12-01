# Contributing to Performance Optimisation Plugin

Thank you for your interest in contributing to the Performance Optimisation plugin! This document provides guidelines and instructions for contributing.

## Table of Contents

1. [Code of Conduct](#code-of-conduct)
2. [Getting Started](#getting-started)
3. [Development Setup](#development-setup)
4. [Coding Standards](#coding-standards)
5. [Development Workflow](#development-workflow)
6. [Testing](#testing)
7. [Pull Request Process](#pull-request-process)
8. [Reporting Bugs](#reporting-bugs)
9. [Suggesting Features](#suggesting-features)

---

## Code of Conduct

### Our Pledge

We are committed to providing a welcoming and inspiring community for all. Please be respectful and constructive in your interactions.

### Expected Behavior

- Use welcoming and inclusive language
- Be respectful of differing viewpoints
- Gracefully accept constructive criticism
- Focus on what is best for the community
- Show empathy towards other community members

### Unacceptable Behavior

- Trolling, insulting comments, or personal attacks
- Public or private harassment
- Publishing others' private information
- Other conduct which could reasonably be considered inappropriate

---

## Getting Started

### Prerequisites

- **PHP 7.4+** (PHP 8.0+ recommended)
- **Node.js 14+** and npm
- **Composer** for PHP dependencies
- **WordPress 6.2+** local installation
- **Git** for version control
- Basic knowledge of WordPress plugin development

### First Steps

1. **Fork the repository** on GitHub
2. **Clone your fork** locally
3. **Set up development environment**
4. **Create a feature branch**
5. **Make your changes**
6. **Submit a pull request**

---

## Development Setup

### 1. Clone the Repository

```bash
# Clone your fork
git clone https://github.com/nilesh-32236/performance-optimisation.git
cd performance-optimisation

# Add upstream remote
git remote add upstream https://github.com/nilesh-32236/performance-optimisation.git
```

### 2. Install Dependencies

```bash
# Install PHP dependencies
composer install

# Install Node.js dependencies
npm install
```

### 3. Build Assets

```bash
# Development build with watch mode
npm run start

# Production build
npm run build
```

### 4. WordPress Setup

```bash
# Symlink to WordPress plugins directory
ln -s /path/to/performance-optimisation /path/to/wordpress/wp-content/plugins/

# Or copy the directory
cp -r performance-optimisation /path/to/wordpress/wp-content/plugins/
```

### 5. Enable WP_DEBUG

```php
// In wp-config.php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);
define('SCRIPT_DEBUG', true); // Load unminified scripts
```

---

## Coding Standards

### PHP Coding Standards

We follow **WordPress PHP Coding Standards** with some enhancements:

#### Naming Conventions

```php
// Classes: PascalCase
class CacheService {}

// Methods and functions: snake_case (WordPress style) or camelCase (modern PHP)
public function clear_cache() {} // WordPress style
public function clearCache() {}  // Modern PHP (we use this)

// Constants: UPPER_CASE
const CACHE_EXPIRY = 3600;

// Variables: snake_case or camelCase
$cache_service;  // WordPress style
$cacheService;   // Modern PHP (we use this)
```

#### Type Hints

Always use type hints for parameters and return types:

```php
public function processImage(int $attachmentId, array $options): array {
    // Implementation
}
```

#### Documentation

Use PHPDoc blocks for all classes, methods, and functions:

```php
/**
 * Clear cache by type.
 *
 * @since 2.0.0
 *
 * @param string $type Cache type to clear (all, page, object).
 * @return bool True on success, false on failure.
 */
public function clearCache(string $type = 'all'): bool {
    // Implementation
}
```

#### Code Formatting

```php
// Spaces around operators
$result = $value + 10;

// Spaces after keywords
if ($condition) {
    // code
}

// Braces on new line for classes and functions
class NewClass
{
    public function newMethod()
    {
        // code
    }
}

// No spaces inside parentheses
function example($param1, $param2)
```

### JavaScript/TypeScript Standards

We use **modern JavaScript (ES6+)** and **TypeScript**:

#### File Structure

```typescript
// Imports at top
import React from 'react';
import { useState } from 'react';

// Type definitions
interface ComponentProps {
    title: string;
    onSave: () => void;
}

// Component
export const Component: React.FC<ComponentProps> = ({ title, onSave }) => {
    const [state, setState] = useState<string>('');
    
    return <div>{title}</div>;
};
```

#### Naming Conventions

```javascript
// Components: PascalCase
const DashboardWidget = () => {};

// Functions and variables: camelCase
const handleClick = () => {};
const userName = 'John';

// Constants: UPPER_CASE
const API_ENDPOINT = '/wp-json/wppo/v1';
```

### CSS Standards

We use **Tailwind CSS** with some custom styles:

```css
/* BEM naming for custom CSS classes */
.dashboard__header {}
.dashboard__header--active {}
.dashboard__title {}

/* Utility-first with Tailwind */
<div className="flex items-center gap-4 p-6">
```

---

## Development Workflow

### Branch Naming

```bash
# Features
git checkout -b feature/add-cdn-integration
git checkout -b feature/critical-css-extraction

# Bug fixes
git checkout -b fix/cache-invalidation-issue
git checkout -b fix/image-optimization-error

# Documentation
git checkout -b docs/update-api-reference
git checkout -b docs/add-examples

# Refactoring
git checkout -b refactor/service-container
```

### Commit Messages

Follow the **Conventional Commits** specification:

```bash
# Format
type(scope): subject

# Examples
feat(cache): add CDN integration support
fix(images): resolve WebP conversion on PHP 8.0
docs(api): update REST API documentation
refactor(services): simplify dependency injection
test(cache): add unit tests for CacheService
chore(deps): update composer dependencies
```

**Types:**
- `feat`: New feature
- `fix`: Bug fix
- `docs`: Documentation changes
- `style`: Code style changes (formatting, etc.)
- `refactor`: Code refactoring
- `test`: Adding or updating tests
- `chore`: Maintenance tasks

### Keeping Your Fork Updated

```bash
# Fetch upstream changes
git fetch upstream

# Merge upstream main into your main
git checkout main
git merge upstream/main

# Rebase your feature branch
git checkout feature/your-feature
git rebase main
```

---

## Testing

### Manual Testing

1. **Test on clean WordPress installation**
2. **Test with popular themes** (Twenty Twenty-Four, Astra, GeneratePress)
3. **Test with popular plugins** (WooCommerce, Contact Form 7)
4. **Test different PHP versions** (7.4, 8.0, 8.1, 8.2)
5. **Use browser developer tools** to check for JavaScript errors

### Automated Testing

```bash
# Run PHP tests (when available)
composer test

# Run JavaScript tests
npm test

# Lint PHP code
composer lint

# Lint JavaScript code
npm run lint
```

### Test Checklist

- [ ] All existing features still work
- [ ] No PHP errors or warnings
- [ ] No JavaScript console errors
- [ ] Code follows coding standards
- [ ] Documentation updated (if needed)
- [ ] No performance regression
- [ ] Mobile responsive (for UI changes)
- [ ] Cross-browser compatible

---

## Pull Request Process

### Before Submitting

1. **Test thoroughly** on local environment
2. **Run linters** and fix any issues
3. **Update documentation** if needed
4. **Add yourself** to CONTRIBUTORS.md (if first contribution)
5. **Rebase on latest main** to avoid conflicts

### PR Title and Description

**Title Format:**
```
type(scope): Brief description
```

**Description Template:**
```markdown
## Description
Brief description of changes

## Type of Change
- [ ] Bug fix
- [ ] New feature
- [ ] Breaking change
- [ ] Documentation update

## Testing
How to test these changes

## Screenshots (if applicable)
Before/after screenshots for UI changes

## Checklist
- [ ] Code follows coding standards
- [ ] Self-review completed
- [ ] Documentation updated
- [ ] No new warnings
- [ ] Tested on WordPress 6.2+
- [ ] Tested on PHP 7.4+
```

### Review Process

1. **Automated checks** must pass (linting, etc.)
2. **Code review** by maintainers
3. **Testing** by maintainers
4. **Feedback addressed** (if any)
5. **Approval and merge**

### After Merge

- Your changes will be included in the next release
- Update your local repository
- Delete your feature branch

---

## Reporting Bugs

### Before Reporting

1. **Search existing issues** to avoid duplicates
2. **Test on latest version** of the plugin
3. **Disable other plugins** to rule out conflicts
4. **Switch to default theme** to rule out theme issues

### Bug Report Template

```markdown
**Describe the Bug**
Clear description of the bug

**To Reproduce**
Steps to reproduce:
1. Go to '...'
2. Click on '...'
3. See error

**Expected Behavior**
What should happen

**Screenshots**
If applicable

**Environment:**
- WordPress version:
- Plugin version:
- PHP version:
- Server environment:
- Active theme:
- Other active plugins:

**Error Messages**
Any error messages from debug.log

**Additional Context**
Any other relevant information
```

---

## Suggesting Features

### Feature Request Template

```markdown
**Feature Description**
Clear description of the proposed feature

**Problem It Solves**
What problem does this solve?

**Proposed Solution**
How should it work?

**Alternatives Considered**
Other solutions you've considered

**Additional Context**
Examples, mockups, or references
```

### Feature Discussion

- Open a **GitHub Discussion** for complex features
- Get feedback from maintainers before implementing
- Check the **roadmap** for planned features

---

## Development Resources

### Documentation
- [Developer Guide](DEVELOPER_GUIDE.md) - Architecture and code examples
- [API Reference](API_REFERENCE.md) - Complete API documentation
- [WordPress Plugin Handbook](https://developer.wordpress.org/plugins/)
- [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/)

### Tools
- [PHP_CodeSniffer](https://github.com/squizlabs/PHP_CodeSniffer) - PHP linting
- [ESLint](https://eslint.org/) - JavaScript linting
- [Prettier](https://prettier.io/) - Code formatting
- [WP-CLI](https://wp-cli.org/) - WordPress command line

### Community
- [WordPress.org Forums](https://wordpress.org/support/plugin/performance-optimisation/)
- [GitHub Discussions](https://github.com/nilesh-32236/performance-optimisation/discussions)
- [Slack/Discord](link-if-available) - Real-time chat

---

## License

By contributing to this project, you agree that your contributions will be licensed under the **GPL v2 or later** license.

---

## Questions?

- Check the [FAQ](USER_GUIDE.md#faq)
- Read the [Developer Guide](DEVELOPER_GUIDE.md)
- Open a [GitHub Discussion](https://github.com/nilesh-32236/performance-optimisation/discussions)
- Ask in [WordPress.org Forums](https://wordpress.org/support/plugin/performance-optimisation/)

---

**Thank you for contributing!** 🎉

Every contribution, no matter how small, helps make this plugin better for everyone.
