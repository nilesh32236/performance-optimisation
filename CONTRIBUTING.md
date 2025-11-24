# Contributing to Performance Optimisation

Thank you for your interest in contributing to Performance Optimisation! We welcome contributions from the community to help make this plugin better.

## How to Contribute

### Reporting Bugs

If you find a bug, please create a new issue on GitHub describing the problem. Include as much detail as possible:

*   Steps to reproduce the issue
*   Expected behavior
*   Actual behavior
*   Screenshots (if applicable)
*   Your environment (WordPress version, PHP version, plugin version)

### Suggesting Enhancements

If you have an idea for a new feature or improvement, please create a new issue on GitHub. Describe your idea and how it would benefit the plugin.

### Pull Requests

1.  **Fork the repository** to your own GitHub account.
2.  **Clone the repository** to your local machine.
3.  **Create a new branch** for your feature or bug fix:
    ```bash
    git checkout -b my-feature-branch
    ```
4.  **Make your changes**. Ensure your code follows the [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/).
5.  **Run tests** to ensure your changes don't break anything:
    ```bash
    composer test
    npm run test
    ```
6.  **Commit your changes** with descriptive commit messages.
7.  **Push your branch** to your fork:
    ```bash
    git push origin my-feature-branch
    ```
8.  **Submit a Pull Request** to the main repository.

## Development Setup

1.  **Install dependencies**:
    ```bash
    composer install
    npm install
    ```
2.  **Build assets**:
    ```bash
    npm run build
    ```
3.  **Start development server** (optional):
    ```bash
    npm run start
    ```

## Coding Standards

*   **PHP**: We follow the [WordPress PHP Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/php/). Run `composer run lint` to check your code.
*   **JavaScript**: We follow the [WordPress JavaScript Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/javascript/). Run `npm run lint` to check your code.

## License

By contributing to Performance Optimisation, you agree that your contributions will be licensed under the [GPLv2 or later](LICENSE.txt).
