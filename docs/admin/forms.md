# Forms and safety sheet

Kasmin has configurable **person forms**. Every label and text is entered once, in the language of your installation.
With the standard content two forms are installed:

- **Safety sheet** – insurance, diet, intolerances, allergies (with severity), permanent medication (name, dose,
  time, who gives it), special medical needs, vaccinations (year / no / unknown), swimming, photo consents
  (internal and publication separately), notes for this year. Applies **until the age of 16** (configurable) and
  has to be **confirmed every year**.
- **General information** – T-shirt size and "I need new T-shirts".

## Form builder

Administrators edit forms under *Forms*: fields with type (text, long text, choice, multiple choice, yes/no, number,
date, year or no/unknown, phone, e-mail, "none or text", repeatable group, person, consent), labels and **help texts (tooltips)**,
required, **confidentiality** (normal, health, ID/insurance number), carry-over to the next
year and use in lists. Every change creates a new **form version**; existing answers keep theirs.

"None" is a real answer and different from "not answered".

- **Choices** have a value and a text, can allow "Other" with text, and can use a ready-made list, e.g. the
  health insurance carriers of Austria, Germany or Switzerland.
- **Numbers and dates** can have a smallest and largest value.
- **Check number**: text fields can check the check digit of an Austrian, German or Swiss insurance number. Extensions
  add further formats and lists in a service provider (`Formats::register()`, `ChoiceLists::register()`).
- **Person**: a legal guardian of the child or another person, if wanted with their insurance number. A number already
  given for a sibling is filled in, only for a legal guardian of both children.

New installations whose time zone is Vienna, Berlin or Zurich get the carriers and the number format of that country.
Existing forms are not changed by an update; change the fields in the form builder if you want the new types.

Once there are answers, only people with access to the **safety sheets** can remove or rename confidential fields, make
them less confidential, or delete the safety sheet. Form rights alone never open health data.

## Export and import

*Events → Forms → Export* saves the definition of a form as a JSON file (no answers), *Import* reads it. A form with the same
key gets a new version; fields that are not in the file are removed, answers stay. The same format works with the API
(`GET`/`PUT /api/v1/forms/{key}/definition`) and MCP (`get_form_definition`, `save_form_definition`).

## Emergency contacts

The **legal guardians are always the first emergency contacts**, taken automatically with their phone numbers.
Further emergency contacts (e.g. grandparents) belong to the **family** and appear on the safety sheet of every
child of the family. The legal guardians choose their order on the page *Emergency contacts*.

## Confirmation and history

Legal guardians fill in the form in the portal with last year's values pre-filled. When the confirmation is due
they can confirm everything unchanged with one click. Every save or confirmation is a new version with date and
person; the history shows which fields changed.

An event can ask for a **newer confirmation** (*Events → Safety sheet not older than … days*): from that number of days
before the start, sheets confirmed earlier count as not confirmed.

## Mails and reminders

The automation *Safety sheet reminders* first sends the request to fill in the sheet, or the yearly confirmation if it
only has to be confirmed, and then reminders. After confirming, the legal guardian gets a thank-you mail; switch off a
mail template if you do not want it. Existing installations get the two new templates with the update.

*Events → Lists → Safety sheets still missing* lists the families; select some and send them a reminder with one click
(needs the right to edit events or safety sheets; a family is not reminded twice within 12 hours).

Webhook `form.confirmed`: form, person and time, never values or field names.

## Confidentiality

- Values are **encrypted** in the database.
- Health and ID fields are **confidential**: they never appear in mails, notifications or the audit log (the log
  records *which* fields changed, never the values). The only exception: a legal guardian can send **themselves** a
  summary after confirming, without insurance and ID numbers (default: no summary). The file is made when the mail is
  sent and is not stored with the mail; it can be sent for 48 hours.
- In the admin interface only the **safety role** and **administrators** see the safety data; every view is logged.
- Children with their own login see their safety sheet read-only.
