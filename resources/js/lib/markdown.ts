/**
 * Minimal typography for markdown rendered server side (an issue body, a
 * recap outline), scoped to `.apui-markdown`. The host's CSS resets lists
 * and headings, and the federated bundle ships no stylesheet.
 */
export const MARKDOWN_CSS = `
.apui-markdown { overflow-wrap: anywhere; font-size: 0.875rem; line-height: 1.5; }
.apui-markdown > * + * { margin-top: 0.75em; }
.apui-markdown h1, .apui-markdown h2, .apui-markdown h3 { font-weight: 600; }
.apui-markdown h1 { font-size: 1.25em; } .apui-markdown h2 { font-size: 1.125em; }
.apui-markdown ul { list-style: disc; padding-left: 1.5em; }
.apui-markdown ol { list-style: decimal; padding-left: 1.5em; }
.apui-markdown a { color: var(--color-primary); text-decoration: underline; }
.apui-markdown code { font-family: ui-monospace, monospace; font-size: 0.85em; background: var(--color-base-200); padding: 0.1em 0.3em; border-radius: 0.25rem; }
.apui-markdown pre { background: var(--color-base-200); padding: 0.75em; border-radius: 0.375rem; overflow-x: auto; }
.apui-markdown pre code { background: none; padding: 0; }
.apui-markdown blockquote { border-left: 3px solid var(--color-base-300); padding-left: 0.75em; opacity: 0.8; }
.apui-markdown img { max-width: 100%; }
.apui-markdown table { border-collapse: collapse; } .apui-markdown th, .apui-markdown td { border: 1px solid var(--color-base-300); padding: 0.25em 0.5em; }
`;
