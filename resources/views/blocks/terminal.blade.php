@php
    /** @var string $chromeStyle */
    /** @var bool $showChrome */
    /** @var string $label */
    /** @var list<array{type:string,content:string}> $lines */
@endphp
<div class="ap-terminal ap-terminal--{{ $chromeStyle }}">
    @if ($showChrome)
        <div class="ap-terminal__chrome">
            <span class="ap-terminal__dots" aria-hidden="true"><span></span><span></span><span></span></span>
            <span class="ap-terminal__label">{{ $label }}</span>
        </div>
    @endif
    <pre class="ap-terminal__body"><code>@foreach ($lines as $line)@switch($line['type'])
@case('command')<span class="ap-terminal__line ap-terminal__line--command"><span class="ap-terminal__prompt">$</span> {{ $line['content'] }}</span>
@break
@case('success')<span class="ap-terminal__line ap-terminal__line--success"><span class="ap-terminal__tick">✓</span> {{ $line['content'] }}</span>
@break
@case('comment')<span class="ap-terminal__line ap-terminal__line--comment">{{ $line['content'] }}</span>
@break
@case('blank')<span class="ap-terminal__line ap-terminal__line--blank"></span>
@break
@default<span class="ap-terminal__line ap-terminal__line--output">{{ $line['content'] }}</span>
@endswitch
@endforeach</code></pre>
</div>
