/**
 * Registry select for the package `registry` field: a `select` custom field
 * with Packagist and npm choices (see `Support/PackageFields.php`).
 *
 * Keystone's dynamic edit screen renders every custom field as a text
 * input, choices included, so the boot module mounts this select beside it
 * and it writes the same `values.registry` form value. Version sync reads
 * the registry to pick Packagist or npm.
 */

import { CARD_CLASS } from '../components/ui';
import type { EditForm } from './IconPickerField';

/** The package custom field this select edits. */
export const REGISTRY_FIELD_KEY = 'registry';

/** Mirrors the field's choices in `PackageFields::definitions()`. */
const REGISTRY_CHOICES = [
    { value: 'packagist', label: 'Packagist' },
    { value: 'npm', label: 'npm' },
] as const;

export function RegistryField({ form }: { form: EditForm }) {
    const value = form.data.values[REGISTRY_FIELD_KEY] ?? '';
    const isKnown = value === '' || REGISTRY_CHOICES.some((choice) => choice.value === value);

    return (
        <section className={CARD_CLASS}>
            <label className="block space-y-2">
                <span className="text-base font-semibold text-base-content">Registry</span>
                <span className="block text-sm text-base-content/60">
                    Where the package is published. Version sync reads its latest stable release from here.
                </span>
                <select
                    className="h-9 w-full max-w-xs rounded-md border border-base-300/60 bg-base-100 px-3 text-sm text-base-content outline-none focus:border-primary"
                    value={value}
                    onChange={(event) =>
                        form.setData('values', { ...form.data.values, [REGISTRY_FIELD_KEY]: event.target.value })
                    }
                >
                    <option value="">Not published</option>
                    {REGISTRY_CHOICES.map((choice) => (
                        <option key={choice.value} value={choice.value}>
                            {choice.label}
                        </option>
                    ))}
                    {!isKnown && <option value={value}>{value} (unknown)</option>}
                </select>
            </label>
        </section>
    );
}
