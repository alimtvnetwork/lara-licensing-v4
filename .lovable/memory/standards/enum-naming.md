# Enum Naming Convention

When creating or modifying ENUMs (both in TypeScript and PHP), try to have the suffix be `Category` or `Type` (e.g., `DockSlotType`, `HttpMethodType`, `StatusCategory`). 
This ensures it is immediately clear across the codebase that the entity is an enum type.

If checking for equality, prefer strict equality checking or static helper methods where applicable, depending on the context.
