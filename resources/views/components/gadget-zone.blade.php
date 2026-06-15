{{-- Renders one zone's gadgets (GadgetService items) in order. Each kind's component owns its
     OpenPNE 3 wrapper markup; this only places them. --}}
@props(['items' => []])
@foreach ($items as $item)
    <x-dynamic-component
        :component="$item['component']"
        :config="$item['config']"
        :subject="$item['subject']"
        :part-id="$item['partId']"
    />
@endforeach
