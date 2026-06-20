{{-- OpenPNE 3 member/profileListBox (listBox parts): the nickname row plus the subject's
     viewer-visible profile values. --}}
@if (count($rows))
    <x-gadget-part :part-id="$partId" part-name="listBox" :title="__('Profile')">
        <table>
            @foreach ($rows as $row)
                <tr>
                    <th>{{ $row['caption'] }}</th>
                    <td>{{ $row['value'] }}</td>
                </tr>
            @endforeach
        </table>
    </x-gadget-part>
@endif
