@php
    /** @var string $command */
    /** @var string $prefix */
    /** @var string $buttonLabel */
    /** @var string $copiedLabel */
@endphp
<div class="ap-clipboard" data-clipboard data-copied-label="{{ $copiedLabel }}">
    <code class="ap-clipboard__value">@if ($prefix !== ''){{ $prefix }} @endif{{ $command }}</code>
    <button
        type="button"
        class="ap-clipboard__button"
        data-clipboard-target
        data-clipboard-value="{{ $command }}"
        aria-label="Copy command"
    >
        <svg
            xmlns="http://www.w3.org/2000/svg"
            viewBox="0 0 16 16"
            width="14"
            height="14"
            aria-hidden="true"
            focusable="false"
            fill="none"
            stroke="currentColor"
            stroke-width="1.5"
            stroke-linecap="round"
            stroke-linejoin="round"
        >
            <rect x="4" y="4" width="9" height="10" rx="1.5"></rect>
            <path d="M4 4V3a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v1"></path>
            <path d="M3 4h.5"></path>
        </svg>
        <span>{{ $buttonLabel }}</span>
    </button>
</div>
