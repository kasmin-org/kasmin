# Forms and safety sheet

Kasmin has configurable **person forms**. With the German or English defaults two are installed:

- **Safety sheet** – insurance, diet, intolerances, allergies (with severity), permanent medication (name, dose,
  time, who gives it), special medical needs, vaccinations (year / no / unknown), swimming, photo consents
  (internal and publication separately), notes for this year. Applies **until the age of 16** (configurable) and
  has to be **confirmed every year**.
- **General information** – T-shirt size and "I need new T-shirts".

## Form builder

Administrators edit forms under *Forms*: fields with type (text, long text, choice, multiple choice, yes/no, date,
year or no/unknown, phone, e-mail, "none or text", repeatable group, consent), labels and **help texts (tooltips)**
in German and English, required, **confidentiality** (normal, health, ID/insurance number), carry-over to the next
year and use in lists. Every change creates a new **form version**; existing answers keep theirs.

"None" is a real answer and different from "not answered".

## Emergency contacts

The **legal guardians are always the first emergency contacts**, taken automatically with their phone numbers.
Further emergency contacts (e.g. grandparents) belong to the **family** and appear on the safety sheet of every
child of the family.

## Confirmation and history

Legal guardians fill in the form in the portal with last year's values pre-filled. When the confirmation is due
they can confirm everything unchanged with one click. Every save or confirmation is a new version with date and
person; the history shows which fields changed.

## Confidentiality

- Values are **encrypted** in the database.
- Health and ID fields are **confidential**: they never appear in mails, notifications or the audit log (the log
  records *which* fields changed, never the values).
- In the admin interface only the **safety role** and **administrators** see the safety data; every view is logged.
- Children with their own login see their safety sheet read-only (portal for participants follows).
