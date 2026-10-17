@php
    /** @var string $inputId */
    /** @var string $label */
    /** @var string $placeholder */
    /** @var string $emptyText */
@endphp
<div class="wp-block-artisanpack-faq-search ap-faq-search" data-faq-search data-empty-text="{{ $emptyText }}">
    <label class="screen-reader-text" for="{{ $inputId }}">{{ $label }}</label>
    <div class="ap-faq-search__field">
        <svg
            class="ap-faq-search__icon"
            xmlns="http://www.w3.org/2000/svg"
            viewBox="0 0 16 16"
            width="16"
            height="16"
            aria-hidden="true"
            focusable="false"
            fill="none"
            stroke="currentColor"
            stroke-width="1.75"
            stroke-linecap="round"
        >
            <circle cx="7" cy="7" r="5"></circle>
            <path d="M11 11l3.5 3.5"></path>
        </svg>
        <input
            id="{{ $inputId }}"
            class="ap-faq-search__input"
            type="search"
            placeholder="{{ $placeholder }}"
            autocomplete="off"
            data-faq-search-input
        >
    </div>
    <p class="ap-faq-search__empty" data-faq-search-empty hidden></p>
    <span class="screen-reader-text" aria-live="polite" data-faq-search-status></span>
</div>
