<x-dynamic-component
    :component="$getFieldWrapperView()"
    :field="$field"
>
    <div
        x-data="{ state: $wire.$entangle('{{ $getStatePath() }}') }"
        role="radiogroup"
        style="display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:0.75rem;"
    >
        @foreach ($getLayoutOptions() as $option)
            <label
                style="display:flex;flex-direction:column;align-items:center;gap:0.5rem;padding:0.75rem;border:2px solid;border-radius:0.75rem;cursor:pointer;transition:border-color .15s,color .15s;"
                :style="state === @js($option['value'])
                    ? 'border-color:var(--primary-500);color:var(--primary-600);'
                    : 'border-color:var(--gray-200);color:var(--gray-400);'"
            >
                <input
                    type="radio"
                    value="{{ $option['value'] }}"
                    x-model="state"
                    style="position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap;border:0;"
                />
                <span style="display:block;width:100%;max-width:9rem;color:inherit;">
                    <x-admin.layout-wireframe :layout="$option['value']" />
                </span>
                <span style="font-size:0.875rem;font-weight:600;color:var(--gray-700);">{{ $option['name'] }}</span>
                <span style="font-size:0.6875rem;line-height:1.3;text-align:center;color:var(--gray-500);">{{ $option['zones'] }}</span>
            </label>
        @endforeach
    </div>
</x-dynamic-component>
