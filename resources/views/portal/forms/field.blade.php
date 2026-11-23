@php($name = 'values['.$field->key.']')
@php($value = $values[$field->key] ?? null)
@php($id = 'f_'.$field->key)
<div class="rounded-xl border border-kasmin-100 p-4">
    <label for="{{ $id }}" class="mb-1 block font-medium">{{ $field->labelText() }}@if ($field->required)<span class="text-red-700"> *</span>@endif</label>
    @if ($field->helpText())<p class="mb-2 text-sm text-kasmin-700">{{ $field->helpText() }}</p>@endif
    @switch($field->type)
        @case('textarea')
            <textarea id="{{ $id }}" name="{{ $name }}" rows="3" class="w-full rounded-lg border border-kasmin-200 px-3 py-2">{{ is_string($value) ? $value : '' }}</textarea>
            @break
        @case('select')
            <select id="{{ $id }}" name="{{ $name }}" class="w-full rounded-lg border border-kasmin-200 px-3 py-3">
                <option value="">–</option>
                @foreach ($field->choices() as $k => $label)<option value="{{ $k }}" @selected((string) $value === (string) $k)>{{ $label }}</option>@endforeach
            </select>
            @break
        @case('multiselect')
            @foreach ($field->choices() as $k => $label)
                <label class="flex items-center gap-2 py-1"><input type="checkbox" name="{{ $name }}[]" value="{{ $k }}" @checked(in_array($k, (array) $value, true))> {{ $label }}</label>
            @endforeach
            @break
        @case('yes_no')
        @case('consent')
            <div class="flex gap-6" role="radiogroup" aria-labelledby="{{ $id }}">
                <label class="flex items-center gap-2 py-2"><input type="radio" name="{{ $name }}" value="yes" @checked($value === 'yes')> {{ __('forms.yes') }}</label>
                <label class="flex items-center gap-2 py-2"><input type="radio" name="{{ $name }}" value="no" @checked($value === 'no')> {{ __('forms.no') }}</label>
            </div>
            @break
        @case('year_or_no')
            <input id="{{ $id }}" name="{{ $name }}" value="{{ is_string($value) ? $value : '' }}" list="{{ $id }}_list" inputmode="numeric" placeholder="{{ __('forms.year_placeholder') }}" class="w-full rounded-lg border border-kasmin-200 px-3 py-3">
            <datalist id="{{ $id }}_list"><option value="no">{{ __('forms.no') }}</option><option value="unknown">{{ __('forms.unknown') }}</option></datalist>
            @break
        @case('none_or_text')
            <div x-data class="space-y-2">
                <label class="flex items-center gap-2"><input type="radio" name="{{ $id }}_mode" value="none" @checked($value === \App\Models\FormField::NONE) onclick="document.getElementById('{{ $id }}').value='{{ \App\Models\FormField::NONE }}';document.getElementById('{{ $id }}_text').value='';"> {{ __('forms.none') }}</label>
                <label class="flex items-center gap-2"><input type="radio" name="{{ $id }}_mode" value="text" @checked(is_string($value) && $value !== \App\Models\FormField::NONE)> {{ __('forms.yes_describe') }}</label>
                <input type="hidden" id="{{ $id }}" name="{{ $name }}" value="{{ is_string($value) ? $value : '' }}">
                <textarea id="{{ $id }}_text" rows="2" class="w-full rounded-lg border border-kasmin-200 px-3 py-2" oninput="document.getElementById('{{ $id }}').value=this.value;document.getElementsByName('{{ $id }}_mode')[1].checked=true;">{{ is_string($value) && $value !== \App\Models\FormField::NONE ? $value : '' }}</textarea>
            </div>
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
    @error('values.'.$field->key)<p class="mt-1 text-sm text-red-700">{{ $message }}</p>@enderror
</div>
