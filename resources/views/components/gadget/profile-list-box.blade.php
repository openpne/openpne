{{-- OpenPNE 3 member/profileListBox (listBox parts): the subject's visible profile values. Skipped
     when nothing is visible, matching OpenPNE 3's empty-content drop. --}}
@if ($fields->isNotEmpty())
    <x-gadget-part :part-id="$partId" part-name="listBox" :title="__('Profile')">
        <table>
            @foreach ($fields as $field)
                <tr>
                    <th>{{ $field->profile->getCaption($lang) }}</th>
                    <td>{{ $field->display($lang) }}</td>
                </tr>
            @endforeach
        </table>
    </x-gadget-part>
@endif
