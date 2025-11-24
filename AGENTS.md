# AGENTS.md

## Purpose
Codex must understand the structure of this plugin and follow a consistent workflow when modifying or generating new code.  
All edits must follow the existing design, styling, folder structure, and coding conventions already used in the plugin.

---

## Core Rules

### 1. Respect the Existing Codebase
- Always follow the existing folder structure.
- Match the naming conventions already used.
- Reuse existing classes, helpers, services, and components when possible.
- Never create new patterns if an existing one already exists.

---

### 2. Styling / UI Consistency
When generating or modifying UI:
- Reuse the existing button styles, form styles, inputs, tables, cards, and layout components.
- Never introduce new colors, fonts, borders, or spacing unless clearly required.
- If adding a new screen or component, copy the style patterns from an existing similar screen.
- Use the same class names and CSS patterns already established in the plugin.

Codex must extract styling rules from the plugin and apply them consistently in all future changes.

---

### 3. Safe Code Modifications
- Never delete existing functionality unless instructed.
- Never break backward compatibility.
- Always check for existing utilities before creating new ones.
- Follow the same service container, hooks, filters, and event patterns used in the plugin.

---

### 4. File Operations
When modifying files:
- Always output the full file content (never partial snippets).
- Provide exact file paths.
- If adding new files, specify the exact directory where they must be created.
- If moving files, specify source and destination paths.

---

### 5. Documentation & Comments
- Add clear comments when introducing new logic.
- Keep comment style consistent with existing files.
- Update documentation blocks when modifying controllers, models, or services.

---

All tasks must be framed as refactoring, consolidation, or improving project structure.

---

## Workflow for All Tasks

1. Load & scan the existing codebase.
2. Identify the correct file(s) to modify.
3. Apply changes inside the existing architecture.
4. Ensure UI & UX conventions remain consistent.
5. Output the updated file(s) with full content.
6. Confirm that the code remains stable and compatible.

---

## Output Requirements
- Always return **complete working code**.
- Always include **full file paths**.
- Always provide **final executed version**, not suggestions.
- Ensure all generated code integrates seamlessly into the plugin.

---

## Summary
Codex must behave like a senior engineer familiar with this plugin:
- Follow structure.
- Follow styling.
- Follow patterns.
- Follow naming.
- Never break functionality.
- Never invent new designs.
- Always produce precise, minimal, correct files.

