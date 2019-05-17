---
{!! $frontmatter !!}
---

{!! $infoText !!}

{!! $prependMd !!}

@if(config('apidoc.vuepress.single-page'))

@foreach($parsedRoutes as $group => $routes)
@if($group)
# {!! $group !!}
@endif
@foreach($routes as $parsedRoute)
{!! isset($parsedRoute['modified_output']) ? $parsedRoute['modified_output'] : $parsedRoute['output'] !!}
@endforeach

<br/>
<br/>

@endforeach{!! $appendMd !!}

@endif
