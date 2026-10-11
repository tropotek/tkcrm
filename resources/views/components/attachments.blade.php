{{--
    The files attached to a record, with a form to add one and a button to delete each.

    <x-attachments :for="$task" />

    The record's model uses App\Attachments\HasAttachments and is in the morph map.
--}}
@props(['for'])

@include('attachments.panel', ['owner' => $for, 'problems' => [], 'typed' => []])
