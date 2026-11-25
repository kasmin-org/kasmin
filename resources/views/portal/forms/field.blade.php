@php($name = 'values['.$field->key.']')
@php($value = $values[$field->key] ?? null)
@php($id = 'f_'.$field->key)
@php($otherText = ($given = collect((array) $value)->first(fn ($v) => is_string($v) && str_starts_with($v, \App\Models\FormField::OTHER))) !== null ? substr($given, strlen(\App\Models\FormField::OTHER)) : null)
<div class="rounded-xl border border-kasmin-100 p-4">
    <label for="{{ $id }}" class="mb-1 block font-medium">{{ $field->labelText() }}@if ($field->required)<span class="text-red-700"> *</span>@endif</label>
    @if ($field->helpText())<p class="mb-2 text-sm text-kasmin-700">{{ $field->helpText() }}</p>@endif
    @switch($field->type)
        @case('textarea')
            <textarea id="{{ $id }}" name="{{ $name }}" rows="3" class="w-full rounded-lg border border-kasmin-200 px-3 py-2">{{ is_string($value) ? $value : '' }}</textarea>
            @break
        @case('select')
            <select id="{{ $id }}" name="{{ $name }}" class="w-full rounded-lg border border-kasmin-200 px-3 py-3" @if ($field->allowsOther()) onchange="document.getElementById('{{ $id }}_other').hidden=this.value!=='{{ \App\Models\FormField::OTHER }}'" @endif>
                <option value="">–</option>
                @foreach ($field->choices() as $k => $label)<option value="{{ $k }}" @selected((string) $value === (string) $k)>{{ $label }}</option>@endforeach
                @if ($field->allowsOther())<option value="{{ \App\Models\FormField::OTHER }}" @selected($otherText !== null)>{{ __('forms.other') }}</option>@endif
            </select>
            @if ($field->allowsOther())<input id="{{ $id }}_other" @if ($otherText === null) hidden @endif name="other[{{ $field->key }}]" value="{{ $otherText }}" maxlength="200" placeholder="{{ __('forms.other_text') }}" aria-label="{{ $field->labelText() }}: {{ __('forms.other_text') }}" class="mt-2 w-full rounded-lg border border-kasmin-200 px-3 py-3">@endif
            @break
        @case('multiselect')
            @foreach ($field->choices() as $k => $label)
                <label class="flex items-center gap-2 py-1"><input type="checkbox" name="{{ $name }}[]" value="{{ $k }}" @checked(in_array((string) $k, array_map('strval', array_filter((array) $value, 'is_scalar')), true))> {{ $label }}</label>
            @endforeach
            @if ($field->allowsOther())
                <label class="flex items-center gap-2 py-1"><input type="checkbox" name="{{ $name }}[]" value="{{ \App\Models\FormField::OTHER }}" @checked($otherText !== null) onchange="document.getElementById('{{ $id }}_other').hidden=!this.checked"> {{ __('forms.other') }}</label>
                <input id="{{ $id }}_other" @if ($otherText === null) hidden @endif name="other[{{ $field->key }}]" value="{{ $otherText }}" maxlength="200" placeholder="{{ __('forms.other_text') }}" aria-label="{{ $field->labelText() }}: {{ __('forms.other_text') }}" class="mt-1 w-full rounded-lg border border-kasmin-200 px-3 py-3">
            @endif
            @break
        @case('yes_no')
        @case('consent')
            <div class="flex gap-6" role="radiogroup" aria-labelledby="{{ $id }}">
                <label class="flex items-center gap-2 py-2"><input type="radio" name="{{ $name }}" value="yes" @checked($value === 'yes')> {{ __('forms.yes') }}</label>
                <label class="flex items-center gap-2 py-2"><input type="radio" name="{{ $name }}" value="no" @checked($value === 'no')> {{ __('forms.no') }}</label>
            </div>
            @break
        @case('year_or_no')
            <input id="{{ $id }}" name="{{ $name }}" value="{{ in_array($value, ['no', 'unknown'], true) ? __('forms.'.$value) : (is_string($value) ? $value : '') }}" list="{{ $id }}_list" placeholder="{{ __('forms.year_placeholder') }}" class="w-full rounded-lg border border-kasmin-200 px-3 py-3">
            <datalist id="{{ $id }}_list"><option value="{{ __('forms.no') }}"></option><option value="{{ __('forms.unknown') }}"></option></datalist>
            @break
        @case('none_or_text')
            <div x-data class="space-y-2">
                <label class="flex items-center gap-2"><input type="radio" name="{{ $id }}_mode" value="none" @checked($value === \App\Models\FormField::NONE) onclick="document.getElementById('{{ $id }}').value='{{ \App\Models\FormField::NONE }}';document.getElementById('{{ $id }}_text').value='';"> {{ __('forms.none') }}</label>
                <label class="flex items-center gap-2"><input type="radio" name="{{ $id }}_mode" value="text" @checked(is_string($value) && $value !== \App\Models\FormField::NONE)> {{ __('forms.yes_describe') }}</label>
                <input type="hidden" id="{{ $id }}" name="{{ $name }}" value="{{ is_string($value) ? $value : '' }}">
                <textarea id="{{ $id }}_text" rows="2" class="w-full rounded-lg border border-kasmin-200 px-3 py-2" oninput="document.getElementById('{{ $id }}').value=this.value;document.getElementsByName('{{ $id }}_mode')[1].checked=true;">{{ is_string($value) && $value !== \App\Models\FormField::NONE ? $value : '' }}</textarea>
            </div>
            @break
        @case('person')
            @php($ref = is_array($value) ? $value : [])
            @php($free = empty($ref['person']) && ! empty($ref['name']))
            <select id="{{ $id }}" name="{{ $name }}[person]" class="w-full rounded-lg border border-kasmin-200 px-3 py-3" onchange="document.getElementById('{{ $id }}_other').hidden=this.value!=='{{ \App\Models\FormField::OTHER }}';var n=document.getElementById('{{ $id }}_number'),v=this.selectedOptions[0].dataset.number;if(n&&v&&!n.value)n.value=v;">
                <option value="">–</option>
                @foreach ($persons[$field->key] ?? [] as $option)<option value="{{ $option['id'] }}" data-number="{{ $option['number'] }}" @selected(($ref['person'] ?? null) === $option['id'])>{{ $option['name'] }}</option>@endforeach
                <option value="{{ \App\Models\FormField::OTHER }}" @selected($free)>{{ __('forms.other_person') }}</option>
            </select>
            <input id="{{ $id }}_other" @unless ($free) hidden @endunless name="other[{{ $field->key }}]" value="{{ $free ? $ref['name'] : '' }}" maxlength="200" placeholder="{{ __('forms.other_person_name') }}" aria-label="{{ $field->labelText() }}: {{ __('forms.other_person_name') }}" class="mt-2 w-full rounded-lg border border-kasmin-200 px-3 py-3">
            @if ($field->options['number'] ?? false)<input id="{{ $id }}_number" name="{{ $name }}[number]" value="{{ $ref['number'] ?? '' }}" maxlength="60" placeholder="{{ __('forms.person_number') }}" aria-label="{{ $field->labelText() }}: {{ __('forms.person_number') }}" class="mt-2 w-full rounded-lg border border-kasmin-200 px-3 py-3">@error('values.'.$field->key.'.number')<p class="mt-1 text-sm text-red-700">{{ $message }}</p>@enderror @endif
            @break
        @case('number')
            <input id="{{ $id }}" name="{{ $name }}" type="number" step="any" inputmode="decimal" @if (filled($field->options['min'] ?? null)) min="{{ $field->options['min'] }}" @endif @if (filled($field->options['max'] ?? null)) max="{{ $field->options['max'] }}" @endif value="{{ is_scalar($value) ? $value : '' }}" class="w-full rounded-lg border border-kasmin-200 px-3 py-3">
            @break
        @case('repeater')
            @php($rows = array_values((array) $value))
            @for ($i = 0; $i < max(count($rows) + 1, 2); $i++)
                <div class="mb-2 grid gap-2 sm:grid-cols-{{ max(1, count($field->subFields())) }}">
                    @foreach ($field->subFields() as $sub)
                        <input name="{{ $name }}[{{ $i }}][{{ $sub }}]" value="{{ $rows[$i][$sub] ?? '' }}" placeholder="{{ $field->subFieldLabel($sub) }}" aria-label="{{ $field->subFieldLabel($sub) }}" class="w-full rounded-lg border border-kasmin-200 px-3 py-2">
                    @endforeach
                </div>
            @endfor
            @break
        @default
            <input id="{{ $id }}" name="{{ $name }}" type="{{ $field->type === 'email' ? 'email' : ($field->type === 'phone' ? 'tel' : ($field->type === 'date' ? 'date' : 'text')) }}" value="{{ is_string($value) ? $value : '' }}" class="w-full rounded-lg border border-kasmin-200 px-3 py-3">
    @endswitch
    @error('values.'.$field->key.'.person')<p class="mt-1 text-sm text-red-700">{{ $message }}</p>@enderror
    @error('values.'.$field->key)<p class="mt-1 text-sm text-red-700">{{ $message }}</p>@enderror
</div>
