# Domain docs

How engineering skills consume this repository's domain documentation.

## Before exploring

- Read `CONTEXT.md` at the repository root when it exists.
- Read applicable decisions under `docs/adr/` when they exist.
- If either location does not exist, proceed silently. Do not create domain documentation until terminology or a decision is actually resolved.

## Layout

This is a single-context repository. The intended layout is:

```text
/
├── CONTEXT.md
├── docs/adr/
└── src/
```

## Vocabulary and decisions

Use terms defined in `CONTEXT.md` when naming domain concepts. If a proposal conflicts with an existing ADR, call out the conflict explicitly instead of silently overriding it.
