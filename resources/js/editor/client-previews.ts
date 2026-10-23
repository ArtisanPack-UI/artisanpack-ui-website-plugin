/**
 * Client-side editor previews for the plugin's attribute-only blocks.
 *
 * `artisanpack/copy-command` and `artisanpack/terminal` are registered from
 * PHP as dynamic blocks, so the visual editor synthesizes an edit component
 * that previews every instance with its own `POST
 * /visual-editor/api/blocks/preview`. Both Blade views only print their
 * attributes into fixed markup, so the editor can draw them itself: a
 * pattern with thirteen copy commands no longer fires thirteen server
 * renders, and sidebar edits repaint without a request.
 *
 * The swap goes through the editor's own `blocks.registerBlockType` filter
 * on `window.ApVisualEditor.libs.hooks` (visual-editor #766), replacing only
 * `edit`. Attributes, supports and the front-end render callback stay
 * sourced from PHP (`src/Blocks`), so there is no second copy of the
 * metadata to drift.
 *
 * Everything is built with `libs.element.createElement`, never JSX: the
 * editor mounts its own React 18 root, and an element from this bundle's
 * React 19 runtime would render detached from it.
 *
 * Keep the markup in step with `resources/views/blocks/copy-command.blade.php`
 * and `resources/views/blocks/terminal.blade.php` — the theme styles both by
 * the same classes.
 */

/** Namespace the filter is registered under, so a remount doesn't add it twice. */
const FILTER_NAMESPACE = 'artisanpack-ui/client-previews';

type CreateElement = (type: unknown, props?: Record<string, unknown> | null, ...children: unknown[]) => unknown;

/** The slice of `window.ApVisualEditor.libs` these previews build against. */
interface EditorLibs {
    element: { createElement: CreateElement; Fragment: unknown };
    blockEditor: {
        useBlockProps: (props?: Record<string, unknown>) => Record<string, unknown>;
        InspectorControls: unknown;
    };
    components: {
        PanelBody: unknown;
        TextControl: unknown;
        TextareaControl: unknown;
        SelectControl: unknown;
        ToggleControl: unknown;
    };
    hooks: {
        addFilter: (hook: string, namespace: string, callback: (...args: never[]) => unknown) => void;
        hasFilter: (hook: string, namespace?: string) => boolean;
    };
}

/** The `apControl` hint `src/Blocks/*::metadata()` puts on each attribute. */
interface ApControl {
    control?: 'text' | 'textarea' | 'select' | 'toggle';
    label?: string;
    help?: string;
    options?: Array<{ label: string; value: string }>;
}

interface AttributeSchema {
    type?: string;
    enum?: unknown[];
    apControl?: ApControl | false;
}

type ControlKind = 'text' | 'textarea' | 'select' | 'toggle';

/**
 * Pick an attribute's control the way the editor's generic server-block edit
 * does (`attribute-controls.tsx` in visual-editor): an explicit `control`
 * wins, then an `enum` or `options` list means a select, then the JSON type.
 */
function controlKind(attribute: AttributeSchema, hint: ApControl): ControlKind {
    if (hint.control) {
        return hint.control;
    }

    if (hint.options || (Array.isArray(attribute.enum) && attribute.enum.length > 0)) {
        return 'select';
    }

    return attribute.type === 'boolean' ? 'toggle' : 'text';
}

/** Select options from the hint, or else from the attribute's `enum`. */
function selectOptions(attribute: AttributeSchema, hint: ApControl): Array<{ label: string; value: string }> {
    if (hint.options) {
        return hint.options;
    }

    return (attribute.enum ?? []).map((value) => {
        const label = String(value);

        return { label: label.charAt(0).toUpperCase() + label.slice(1), value: label };
    });
}

interface BlockSettings {
    attributes?: Record<string, AttributeSchema>;
    edit?: unknown;
    [key: string]: unknown;
}

interface EditProps {
    attributes: Record<string, unknown>;
    setAttributes: (attributes: Record<string, unknown>) => void;
}

type Attributes = Record<string, unknown>;

declare global {
    interface Window {
        ApVisualEditor?: { libs?: EditorLibs };
    }
}

/** Read a string attribute, falling back when the editor left it unset. */
function text(attributes: Attributes, name: string, fallback: string): string {
    const value = attributes[name];

    return typeof value === 'string' ? value : fallback;
}

/**
 * The inspector panel the editor's generic server-block edit would have
 * drawn, under the same "Settings" title.
 *
 * Only attributes carrying an `apControl` hint get a control. Every editable
 * attribute in `src/Blocks` has one, and by the time this filter runs the
 * settings may also hold attributes the editor's own filters injected
 * (visibility, responsive, …), which have their own UI and are often
 * objects a text field would corrupt.
 */
function inspector(libs: EditorLibs, schema: Record<string, AttributeSchema>, { attributes, setAttributes }: EditProps): unknown {
    const h = libs.element.createElement;
    const { PanelBody, TextControl, TextareaControl, SelectControl, ToggleControl } = libs.components;

    const controls = Object.entries(schema).flatMap(([name, attribute]) => {
        const hint = attribute.apControl;

        if (!hint || typeof hint !== 'object') {
            return [];
        }

        const common = {
            key: name,
            label: hint.label ?? name,
            help: hint.help,
            __nextHasNoMarginBottom: true,
        };
        const kind = controlKind(attribute, hint);

        if (kind === 'toggle') {
            return [
                h(ToggleControl, {
                    ...common,
                    checked: attributes[name] === true,
                    onChange: (next: boolean) => setAttributes({ [name]: next }),
                }),
            ];
        }

        const value = typeof attributes[name] === 'string' ? attributes[name] : '';
        const onChange = (next: string) => setAttributes({ [name]: next });

        if (kind === 'select') {
            return [h(SelectControl, { ...common, __next40pxDefaultSize: true, value, options: selectOptions(attribute, hint), onChange })];
        }

        if (kind === 'textarea') {
            return [h(TextareaControl, { ...common, value, rows: 8, onChange })];
        }

        return [h(TextControl, { ...common, __next40pxDefaultSize: true, value, onChange })];
    });

    return h(libs.blockEditor.InspectorControls, null, h(PanelBody, { title: 'Settings' }, ...controls));
}

/**
 * Mirror of `copy-command.blade.php`. `useBlockProps` contributes the
 * `wp-block-artisanpack-copy-command` class and the supports' styles, so
 * the wrapper is the same root the front end renders.
 */
function copyCommandPreview(libs: EditorLibs, attributes: Attributes): unknown {
    const h = libs.element.createElement;
    const command = text(attributes, 'command', 'composer require artisanpack-ui/core');
    const prefix = text(attributes, 'prefix', '$');
    const buttonLabel = text(attributes, 'buttonLabel', 'Copy');
    const copiedLabel = text(attributes, 'copiedLabel', 'Copied');

    const blockProps = libs.blockEditor.useBlockProps({
        className: 'ap-clipboard',
        'data-clipboard': '',
        'data-copied-label': copiedLabel,
    });

    const icon = h(
        'svg',
        {
            xmlns: 'http://www.w3.org/2000/svg',
            viewBox: '0 0 16 16',
            width: 14,
            height: 14,
            'aria-hidden': 'true',
            focusable: 'false',
            fill: 'none',
            stroke: 'currentColor',
            strokeWidth: 1.5,
            strokeLinecap: 'round',
            strokeLinejoin: 'round',
        },
        h('rect', { x: 4, y: 4, width: 9, height: 10, rx: 1.5 }),
        h('path', { d: 'M4 4V3a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v1' }),
        h('path', { d: 'M3 4h.5' }),
    );

    return h(
        'div',
        blockProps,
        h(
            'code',
            { className: 'ap-clipboard__value' },
            prefix !== '' ? h('span', { className: 'ap-clipboard__prompt', 'aria-hidden': 'true' }, prefix) : null,
            prefix !== '' ? ' ' : null,
            command,
        ),
        ' ',
        h(
            'button',
            {
                type: 'button',
                className: 'ap-clipboard__button',
                'data-clipboard-target': '',
                'data-clipboard-value': command,
            },
            ' ',
            icon,
            ' ',
            h('span', null, buttonLabel),
            ' ',
            h('span', { className: 'sr-only' }, ` ${command}`),
            ' ',
        ),
        ' ',
        h('span', { className: 'sr-only', 'aria-live': 'polite', 'data-clipboard-status': '' }),
    );
}

type TerminalLine = { type: 'command' | 'success' | 'comment' | 'blank' | 'output'; content: string };

/** Port of `TerminalBlock::parseLines()`: typed rows from the raw textarea. */
function parseTerminalLines(raw: string): TerminalLine[] {
    return raw.split(/\r\n|\n|\r/).map((line): TerminalLine => {
        if (line.trim() === '') {
            return { type: 'blank', content: '' };
        }

        if (line.startsWith('$ ')) {
            return { type: 'command', content: line.slice(2) };
        }

        if (line.startsWith('✓ ')) {
            return { type: 'success', content: line.slice('✓ '.length) };
        }

        if (line.startsWith('# ')) {
            return { type: 'comment', content: line.slice(2) };
        }

        return { type: 'output', content: line };
    });
}

const TERMINAL_CHROME_STYLES = ['terminal', 'editor', 'browser'] as const;

/** Mirror of `terminal.blade.php`, with `TerminalBlock::render()`'s fallbacks. */
function terminalPreview(libs: EditorLibs, attributes: Attributes): unknown {
    const h = libs.element.createElement;
    const requested = text(attributes, 'chromeStyle', 'terminal');
    const chromeStyle = (TERMINAL_CHROME_STYLES as readonly string[]).includes(requested) ? requested : 'terminal';
    const defaultLabel = chromeStyle === 'browser' ? 'localhost' : chromeStyle;
    const label = text(attributes, 'label', '').trim() || defaultLabel;
    const showChrome = attributes.showChrome !== false;

    const blockProps = libs.blockEditor.useBlockProps({
        className: `ap-terminal ap-terminal--${chromeStyle}`,
    });

    const lines = parseTerminalLines(text(attributes, 'lines', '')).flatMap((line, index) => {
        const className = `ap-terminal__line ap-terminal__line--${line.type}`;
        let row: unknown;

        if (line.type === 'command') {
            row = h('span', { key: index, className }, h('span', { className: 'ap-terminal__prompt' }, '$'), ` ${line.content}`);
        } else if (line.type === 'success') {
            row = h('span', { key: index, className }, h('span', { className: 'ap-terminal__tick' }, '✓'), ` ${line.content}`);
        } else {
            row = h('span', { key: index, className }, line.type === 'blank' ? null : line.content);
        }

        return [row, '\n'];
    });

    return h(
        'div',
        blockProps,
        showChrome
            ? h(
                  'div',
                  { className: 'ap-terminal__chrome' },
                  h('span', { className: 'ap-terminal__dots', 'aria-hidden': 'true' }, h('span'), h('span'), h('span')),
                  ' ',
                  h('span', { className: 'ap-terminal__label' }, label),
              )
            : null,
        h('pre', { className: 'ap-terminal__body' }, h('code', null, ...lines)),
    );
}

const PREVIEWS: Record<string, (libs: EditorLibs, attributes: Attributes) => unknown> = {
    'artisanpack/copy-command': copyCommandPreview,
    'artisanpack/terminal': terminalPreview,
};

/** Build the client `edit` for one block: its inspector plus its preview. */
function createEdit(libs: EditorLibs, name: string, schema: Record<string, AttributeSchema>) {
    const preview = PREVIEWS[name];

    function ClientPreviewEdit(props: EditProps): unknown {
        return libs.element.createElement(
            libs.element.Fragment,
            null,
            inspector(libs, schema, props),
            preview(libs, props.attributes),
        );
    }

    ClientPreviewEdit.displayName = `ClientPreviewEdit(${name})`;

    return ClientPreviewEdit;
}

/**
 * Install the `blocks.registerBlockType` filter on the visual editor's hooks
 * instance. Must run before the editor registers its server blocks, which
 * it does once its block library has loaded; Keystone's
 * `keystone.admin.visualEditor.beforeBoot` action is early enough. A block
 * registered before the filter lands keeps the server preview, which still
 * works. Safe to call repeatedly.
 */
export function registerClientPreviews(): void {
    const libs = typeof window === 'undefined' ? undefined : window.ApVisualEditor?.libs;

    if (!libs || libs.hooks.hasFilter('blocks.registerBlockType', FILTER_NAMESPACE)) {
        return;
    }

    libs.hooks.addFilter('blocks.registerBlockType', FILTER_NAMESPACE, ((settings: BlockSettings, name: string) => {
        if (!(name in PREVIEWS)) {
            return settings;
        }

        return { ...settings, edit: createEdit(libs, name, settings.attributes ?? {}) };
    }) as (...args: never[]) => unknown);
}
