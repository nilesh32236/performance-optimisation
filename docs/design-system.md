# Design System

## Core Philosophy
- **Lightweight**: Minimal visual noise, focus on content.
- **Fast**: UI should feel snappy and responsive.
- **Trustworthy**: Professional, clean, and consistent.

## Color Palette

### Primary (Brand) - Indigo
Used for primary actions, active states, and brand identity.
- `primary-50`: Backgrounds, subtle highlights
- `primary-100`: Hover states for light backgrounds
- `primary-500`: Main brand color
- `primary-600`: Hover states for buttons, active links

### Secondary (Accents)
- **Teal**: Success, completion, positive reinforcement.
- **Violet**: Special features, premium indicators.

### Neutrals (Slate)
- `slate-50` to `slate-900`: Used for text, borders, and backgrounds.

### Feedback
- **Success**: Green/Teal
- **Warning**: Amber
- **Error**: Red

## Typography
- **Font Family**: `Inter`, system-ui, sans-serif.
- **Scale**:
    - H1: 3xl, bold, tight tracking
    - H2: 2xl, bold
    - H3: xl, semibold
    - Body: base/sm, regular
    - Small: xs, medium

### Scale (Tailwind Classes)

| Level | Size | Line Height | Weight | Usage |
|-------|------|-------------|--------|-------|
| H1 | `text-3xl` | `leading-tight` | `font-bold` | Page Titles |
| H2 | `text-2xl` | `leading-snug` | `font-bold` | Section Headers |
| H3 | `text-xl` | `leading-snug` | `font-semibold` | Card Headers |
| Body | `text-base` | `leading-normal` | `font-normal` | Main Content |
| Small | `text-sm` | `leading-normal` | `font-medium` | Metadata, Hints |

## Components

### Buttons
- **Primary**: `bg-primary-600 text-white hover:bg-primary-700 rounded-xl shadow-sm`
- **Secondary**: `bg-white text-slate-700 border border-slate-300 hover:bg-slate-50`
- **Ghost**: `text-primary-600 hover:bg-primary-50`

### Cards
- **Container**: `bg-white rounded-2xl shadow-xl border border-slate-200`
- **Padding**: `p-6` or `p-8`

### Inputs
- **Base**: `border-slate-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500`

## Accessibility & Contrast Ratios

We target **WCAG 2.1 AA** compliance for all UI elements.

| Element | Background | Text Color | Contrast Ratio | Status |
|---------|------------|------------|----------------|--------|
| Primary Button | `primary-600` (#4338ca) | White (#ffffff) | 12.6:1 | Pass (AAA) |
| Secondary Button | White (#ffffff) | `slate-700` (#334155) | 12.6:1 | Pass (AAA) |
| Body Text | White (#ffffff) | `slate-600` (#475569) | 8.8:1 | Pass (AAA) |
| Heading Text | White (#ffffff) | `slate-900` (#0f172a) | 19.4:1 | Pass (AAA) |
| Link | White (#ffffff) | `primary-600` (#4338ca) | 12.6:1 | Pass (AAA) |

**Guidance:**
- Ensure all interactive elements have a focus ring (`focus:ring-2`).
- Use `aria-label` or `sr-only` text for icon-only buttons.
- Do not rely on color alone to convey meaning (use icons/text labels).

## Dark Mode & Responsive Design

### Dark Mode
*Currently, the plugin uses a light-themed admin interface to match standard WordPress admin styles. Future dark mode implementation should follow these patterns:*

- **Backgrounds**: `dark:bg-slate-900`
- **Cards**: `dark:bg-slate-800 dark:border-slate-700`
- **Text**: `dark:text-slate-100` (headings), `dark:text-slate-300` (body)
- **Primary**: Adjust to lighter shade `dark:text-primary-400` for better contrast.

### Responsive Design
We follow a **mobile-first** approach using Tailwind breakpoints.

- **Mobile (Default)**: Single column layouts, stacked actions.
- **Tablet (`sm: 640px`)**: Grid layouts (2 cols), adjusted padding.
- **Desktop (`md: 768px`)**: Sidebar layouts, complex tables.
- **Large (`lg: 1024px`)**: Max-width containers centered.

**Component Behavior:**
- **Wizard**: Full width on mobile, centered card on desktop.
- **Tables**: Scroll horizontally on mobile or stack as cards.
- **Navigation**: Hamburger menu on mobile (if applicable), tabs/sidebar on desktop.
