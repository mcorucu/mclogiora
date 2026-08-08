# ERD

```text
wp_mclogiora_languages
  language_code (unique)
  locale (unique)

wp_mclogiora_translation_groups
  group_uuid (unique)
  source_content_type
  source_content_id
  source_language

wp_mclogiora_translation_items
  group_uuid -> wp_mclogiora_translation_groups.group_uuid
  content_type
  content_id
  language_code
```

Relationship:

```text
translation_groups 1 ---- * translation_items
languages.language_code 1 ---- * translation_items.language_code
languages.language_code 1 ---- * translation_groups.source_language
```

Foreign keys are not declared in Phase 06 because WordPress plugins commonly support hosts with mixed table engines and migration constraints. Relationships are enforced at the repository/service layer and documented for future validation.
