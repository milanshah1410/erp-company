<x-mail::message>
{{ $greeting }}

{{ $body }}

@if (! empty($companyInfo['name']))
Thanks &amp; regards,
**{{ $companyInfo['name'] }}**
@if (! empty($companyInfo['phone']))
{{ $companyInfo['phone'] }}
@endif
@if (! empty($companyInfo['email']))
{{ $companyInfo['email'] }}
@endif
@endif

<small>The document is attached as a PDF for your records.</small>
</x-mail::message>
