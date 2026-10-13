# Design System

## Direction

Retain Loveworld's navy and green identity with YLocal as the primary typeface. The register is restrained institutional-premium: calm surfaces, strong hierarchy, clear data states, and purposeful emphasis inspired by Stripe, Linear, and Notion without copying their visual language.

## Color strategy

Use green for primary actions, active progress, and success. Use navy for navigation, identity, and high-contrast headings. Use cool near-white surfaces and semantic amber, red, and blue states for warning, error, and information. Accent color should signal meaning, not decorate inactive content.

## Layout strategy

Use predictable product layouts: persistent desktop navigation, compact mobile navigation, page headers with context and one primary action, readable content widths, dense tables for operations, and progressive disclosure for complex forms. Use a 4px-based spacing scale with tight grouping inside components and generous separation between workflow stages.

## Components

Build and reuse semantic components for page headers, breadcrumbs, buttons, fields, validation summaries, status badges, progress steppers, payment summaries, tables, filters, empty states, skeletons, toasts, confirmation dialogs, and mobile action bars. Each interactive component must have default, hover, focus, active, disabled, loading, error, and success behavior.

## Typography

Use YLocal consistently across Bootstrap and Flux surfaces. Keep product headings on a fixed rem scale with a restrained ratio, use readable line length for explanations, and keep labels and data compact. Headings should wrap intentionally and never overflow narrow screens.

## Motion

Use 150–250ms state transitions for feedback, reveal, and navigation context. Avoid decorative page-load choreography and large layout animations. Provide reduced-motion alternatives and never make a form or payment action wait for animation.

## Responsive behavior

Design mobile as a first-class task context: stack complex layouts, use bottom sheets for filters, keep primary actions reachable, use full-width fields, preserve the same information architecture, and transform tables into readable cards or horizontal scroll regions without hiding essential actions.
