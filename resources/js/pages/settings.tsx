/**
 * Settings — federated remote `artisanpack-ui`, exposed as `./settings`,
 * resolved by the host at `plugins/artisanpack-ui/settings`.
 *
 * Connection details for the docs site API and the GitHub App installed on
 * the ArtisanPack-UI org, plus a "Test connection" check for each. Saving
 * and testing go through the plugin's JSON endpoints; the checks run
 * against the *saved* settings, so a test after editing asks to save first.
 *
 * The docs API token and the App private key are write-only: the server
 * only says whether one is stored. Leaving the field blank keeps it, and
 * "Remove" clears it on the next save.
 */

import { useState, type FormEvent, type ReactNode } from 'react';

import { CARD_CLASS, PluginPage } from '../components/ui';
import { ApiError, apiFetch } from '../lib/http';
import type { ConnectionCheck, IntegrationSettings, SettingsPageProps } from '../lib/types';

const INPUT_CLASS =
    'h-9 w-full rounded-md border border-base-300/60 bg-base-100 px-3 text-sm text-base-content outline-none focus:border-primary';
const TEXTAREA_CLASS =
    'w-full rounded-md border border-base-300/60 bg-base-100 px-3 py-2 font-mono text-xs text-base-content outline-none focus:border-primary';

interface FormState {
    docs_base_url: string;
    docs_api_token: string;
    remove_docs_api_token: boolean;
    github_app_id: string;
    github_installation_id: string;
    github_private_key: string;
    remove_github_private_key: boolean;
    github_organization: string;
    github_project_number: string;
}

function formFrom(settings: IntegrationSettings): FormState {
    return {
        docs_base_url: settings.docsBaseUrl ?? '',
        docs_api_token: '',
        remove_docs_api_token: false,
        github_app_id: settings.githubAppId ?? '',
        github_installation_id: settings.githubInstallationId ?? '',
        github_private_key: '',
        remove_github_private_key: false,
        github_organization: settings.githubOrganization,
        github_project_number: settings.githubProjectNumber === null ? '' : String(settings.githubProjectNumber),
    };
}

type CheckState = { running: boolean; result: ConnectionCheck | null };

export default function SettingsPage({ nav, can, settings: initialSettings, endpoints }: SettingsPageProps) {
    const [settings, setSettings] = useState(initialSettings);
    const [form, setForm] = useState<FormState>(() => formFrom(initialSettings));
    const [dirty, setDirty] = useState(false);
    const [saving, setSaving] = useState(false);
    const [notice, setNotice] = useState<{ ok: boolean; message: string } | null>(null);
    const [errors, setErrors] = useState<Record<string, string[]>>({});
    const [docsCheck, setDocsCheck] = useState<CheckState>({ running: false, result: null });
    const [gitHubCheck, setGitHubCheck] = useState<CheckState>({ running: false, result: null });

    function update<K extends keyof FormState>(key: K, value: FormState[K]) {
        setForm((current) => ({ ...current, [key]: value }));
        setDirty(true);
    }

    async function save(event: FormEvent) {
        event.preventDefault();
        setSaving(true);
        setNotice(null);
        setErrors({});

        try {
            const response = await apiFetch<{ message: string; settings: IntegrationSettings }>(endpoints.update, {
                method: 'PUT',
                body: JSON.stringify({
                    ...form,
                    github_project_number: form.github_project_number === '' ? null : Number(form.github_project_number),
                }),
            });

            setSettings(response.settings);
            setForm(formFrom(response.settings));
            setDirty(false);
            setDocsCheck({ running: false, result: null });
            setGitHubCheck({ running: false, result: null });
            setNotice({ ok: true, message: response.message });
        } catch (error) {
            if (error instanceof ApiError) {
                setErrors(error.errors);
            }
            setNotice({ ok: false, message: error instanceof Error ? error.message : 'Saving failed.' });
        } finally {
            setSaving(false);
        }
    }

    async function runCheck(url: string, setState: (state: CheckState) => void) {
        setState({ running: true, result: null });

        try {
            setState({ running: false, result: await apiFetch<ConnectionCheck>(url, { method: 'POST' }) });
        } catch (error) {
            setState({
                running: false,
                result: { ok: false, message: error instanceof Error ? error.message : 'The check failed.', details: [] },
            });
        }
    }

    return (
        <PluginPage
            nav={nav}
            can={can}
            active="settings"
            title="ArtisanPack UI settings"
            description="Connections to the docs site and the ArtisanPack-UI GitHub App."
        >
            <form onSubmit={save} className="space-y-6">
                <section className={CARD_CLASS}>
                    <h2 className="text-base font-semibold text-base-content">Docs site</h2>
                    <p className="mt-1 text-sm text-base-content/60">
                        The docs site's Sanctum API. The token needs the abilities for what you use: packages,
                        docs and changelogs read/write, and <code>imports:trigger</code>.
                    </p>

                    <div className="mt-5 grid gap-4 md:grid-cols-2">
                        <Field label="Base URL" error={errors.docs_base_url} hint="e.g. https://docs.artisanpackui.dev">
                            <input
                                type="url"
                                className={INPUT_CLASS}
                                value={form.docs_base_url}
                                onChange={(event) => update('docs_base_url', event.target.value)}
                            />
                        </Field>
                        <SecretField
                            label="API token"
                            stored={settings.hasDocsApiToken}
                            removing={form.remove_docs_api_token}
                            error={errors.docs_api_token}
                            onRemove={(removing) => update('remove_docs_api_token', removing)}
                        >
                            <input
                                type="password"
                                autoComplete="off"
                                className={INPUT_CLASS}
                                value={form.docs_api_token}
                                placeholder={settings.hasDocsApiToken ? 'Saved — leave blank to keep' : ''}
                                onChange={(event) => update('docs_api_token', event.target.value)}
                            />
                        </SecretField>
                    </div>

                    <ConnectionTest
                        label="Test docs site connection"
                        state={docsCheck}
                        dirty={dirty}
                        onRun={() => runCheck(endpoints.testDocs, setDocsCheck)}
                    />
                </section>

                <section className={CARD_CLASS}>
                    <h2 className="text-base font-semibold text-base-content">GitHub App</h2>
                    <p className="mt-1 text-sm text-base-content/60">
                        The GitHub App installed on the org. Stats, the boards and the Command Center API read and
                        write GitHub through it.
                    </p>

                    <div className="mt-5 grid gap-4 md:grid-cols-2">
                        <Field label="App ID" error={errors.github_app_id}>
                            <input
                                className={INPUT_CLASS}
                                value={form.github_app_id}
                                onChange={(event) => update('github_app_id', event.target.value)}
                            />
                        </Field>
                        <Field label="Installation ID" error={errors.github_installation_id}>
                            <input
                                inputMode="numeric"
                                className={INPUT_CLASS}
                                value={form.github_installation_id}
                                onChange={(event) => update('github_installation_id', event.target.value)}
                            />
                        </Field>
                        <Field label="Organization" error={errors.github_organization}>
                            <input
                                className={INPUT_CLASS}
                                value={form.github_organization}
                                onChange={(event) => update('github_organization', event.target.value)}
                            />
                        </Field>
                        <Field
                            label="Project (v2) number"
                            error={errors.github_project_number}
                            hint="The number in the org project's URL."
                        >
                            <input
                                type="number"
                                min={1}
                                className={INPUT_CLASS}
                                value={form.github_project_number}
                                onChange={(event) => update('github_project_number', event.target.value)}
                            />
                        </Field>
                        <div className="md:col-span-2">
                            <SecretField
                                label="Private key"
                                stored={settings.hasGitHubPrivateKey}
                                removing={form.remove_github_private_key}
                                error={errors.github_private_key}
                                onRemove={(removing) => update('remove_github_private_key', removing)}
                                hint="Paste the whole .pem file GitHub generated for the App."
                            >
                                <textarea
                                    rows={6}
                                    autoComplete="off"
                                    spellCheck={false}
                                    className={TEXTAREA_CLASS}
                                    value={form.github_private_key}
                                    placeholder={settings.hasGitHubPrivateKey ? 'Saved — leave blank to keep' : '-----BEGIN RSA PRIVATE KEY-----'}
                                    onChange={(event) => update('github_private_key', event.target.value)}
                                />
                            </SecretField>
                        </div>
                    </div>

                    <ConnectionTest
                        label="Test GitHub connection"
                        state={gitHubCheck}
                        dirty={dirty}
                        onRun={() => runCheck(endpoints.testGitHub, setGitHubCheck)}
                    />
                </section>

                <div className="flex items-center gap-3">
                    <button type="submit" className="btn btn-primary btn-sm" disabled={saving}>
                        {saving ? 'Saving…' : 'Save settings'}
                    </button>
                    {notice && (
                        <p role="status" className={`text-sm ${notice.ok ? 'text-success' : 'text-error'}`}>
                            {notice.message}
                        </p>
                    )}
                </div>
            </form>
        </PluginPage>
    );
}

function Field({ label, error, hint, children }: { label: string; error?: string[]; hint?: string; children: ReactNode }) {
    return (
        <label className="block space-y-1.5">
            <span className="text-sm font-medium text-base-content">{label}</span>
            {children}
            {hint && !error && <span className="block text-xs text-base-content/55">{hint}</span>}
            {error && <span className="block text-xs text-error">{error[0]}</span>}
        </label>
    );
}

function SecretField({
    label,
    stored,
    removing,
    error,
    hint,
    onRemove,
    children,
}: {
    label: string;
    stored: boolean;
    removing: boolean;
    error?: string[];
    hint?: string;
    onRemove: (removing: boolean) => void;
    children: ReactNode;
}) {
    return (
        <div className="space-y-1.5">
            <Field label={label} error={error} hint={hint}>
                {children}
            </Field>
            {stored && (
                <label className="flex items-center gap-2 text-xs text-base-content/70">
                    <input
                        type="checkbox"
                        className="checkbox checkbox-xs"
                        checked={removing}
                        onChange={(event) => onRemove(event.target.checked)}
                    />
                    Remove the saved {label.toLowerCase()} on save
                </label>
            )}
        </div>
    );
}

function ConnectionTest({
    label,
    state,
    dirty,
    onRun,
}: {
    label: string;
    state: CheckState;
    dirty: boolean;
    onRun: () => void;
}) {
    return (
        <div className="mt-5 space-y-2 border-t border-base-300/60 pt-4">
            <div className="flex flex-wrap items-center gap-3">
                <button type="button" className="btn btn-outline btn-sm" onClick={onRun} disabled={state.running || dirty}>
                    {state.running ? 'Testing…' : label}
                </button>
                {dirty && <span className="text-xs text-base-content/55">Save your changes to test them.</span>}
            </div>
            {state.result && (
                <div
                    role="status"
                    className={`rounded-md border px-3 py-2 text-sm ${
                        state.result.ok ? 'border-success/40 text-success' : 'border-error/40 text-error'
                    }`}
                >
                    <p className="font-medium">{state.result.message}</p>
                    {state.result.details.length > 0 && (
                        <ul className="mt-1 list-disc space-y-0.5 pl-5 text-xs text-base-content/70">
                            {state.result.details.map((detail) => (
                                <li key={detail}>{detail}</li>
                            ))}
                        </ul>
                    )}
                </div>
            )}
        </div>
    );
}
