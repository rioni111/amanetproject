{{-- Plain-text alternative. Not HTML: values are output raw on purpose (escaping would show &amp; etc.). --}}
{!! $firmName !!}

{!! $c->headline !!}

{!! $c->greeting !!}

@foreach ($c->paragraphs as $paragraph)
{!! $paragraph !!}

@endforeach
@foreach ($c->details as $row)
{!! $row['label'] !!}: {!! $row['value'] !!}
@endforeach
@if (count($c->details) > 0)

@endif
@if ($c->quote !== null)
{!! $c->quoteLabel !!}
{!! $c->quote !!}

@endif
@if ($c->actionUrl !== null)
{!! $c->actionLabel !!}:
{!! $c->actionUrl !!}
(Open the complete address, including everything after the # sign.)

@endif
@foreach ($c->notes as $note)
{!! $note !!}
@endforeach

--
{!! $firmName !!}@if ($postalAddress) · {!! $postalAddress !!}@endif

@if ($contactEmail)
Questions? Reply to this e-mail or write to {!! $contactEmail !!}.
@else
Questions? Reply to this e-mail.
@endif
Sent securely with Lexora Workspace. We will never ask for your password.
