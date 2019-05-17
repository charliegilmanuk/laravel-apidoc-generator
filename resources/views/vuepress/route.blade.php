<!-- START_{{$route['id']}} -->
@if($route['title'] != '')## {{ $route['title']}}
@else## {{$route['uri']}}@endif
### HTTP Request
@foreach($route['methods'] as $method)
`{{$method}} {{$route['uri']}}`

@endforeach
@if($route['description'])

{!! $route['description'] !!}
@endif

### Example request:

@foreach($settings['languages'] as $language)
@include("apidoc::partials.example-requests.$language")

@endforeach

@if(in_array('GET',$route['methods']) || (isset($route['showresponse']) && $route['showresponse']))
@if(is_array($route['response']))
@foreach($route['response'] as $response)
### Example response ({{$response['status']}}):

```json
@if(is_object($response['content']) || is_array($response['content']))
{!! json_encode($response['content'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) !!}
@else
{!! json_encode(json_decode($response['content']), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) !!}
@endif
```
@endforeach
@else
### Example response:

```json
@if(is_object($route['response']) || is_array($route['response']))
{!! json_encode($route['response'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) !!}
@else
{!! json_encode(json_decode($route['response']), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) !!}
@endif
```
@endif
@endif

@if(count($route['bodyParameters']))
#### Body Parameters

Parameter | Type | Status | Description
--------- | ------- | ------- | ------- | -----------
@foreach($route['bodyParameters'] as $attribute => $parameter)
{{$attribute}} | {{$parameter['type']}} | @if($parameter['required']) required @else optional @endif | {!! $parameter['description'] !!}
@endforeach
@endif
@if(count($route['queryParameters']))
#### Query Parameters

Parameter | Status | Description
--------- | ------- | ------- | -----------
@foreach($route['queryParameters'] as $attribute => $parameter)
{{ $attribute }} | @if($parameter['required']) required @else optional @endif | {!! $parameter['description'] !!}
@endforeach
@endif

<br />
<br />
<br />
