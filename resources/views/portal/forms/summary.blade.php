@if ($form->family_emergency_contacts)
    <label class="mb-3 block text-sm" for="summary_{{ $id }}"><span class="mb-1 block font-medium">{{ __('forms.summary') }}</span>
        <select id="summary_{{ $id }}" name="summary" class="w-full rounded-lg border border-kasmin-200 px-3 py-3">
            <option value="none">{{ __('forms.summary_none') }}</option>
            <option value="without_numbers" @selected(old('summary') === 'without_numbers')>{{ __('forms.summary_without_numbers') }}</option>
        </select>
        <span class="mt-1 block text-kasmin-700">{{ __('forms.summary_hint') }}</span>
    </label>
@endif
