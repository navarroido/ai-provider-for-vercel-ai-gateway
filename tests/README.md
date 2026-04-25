# Tests

Test scaffolding for the AI Provider for Vercel AI Gateway plugin.

The MVP ships without a test suite; this directory exists so that
contributors have a clear home for forthcoming PHPUnit tests. Suggested
layout when tests are added:

```
tests/
├── bootstrap.php              # PHPUnit autoloader entry point
├── phpunit.xml.dist
├── Unit/
│   └── Providers/
│       └── VercelAIGateway/
│           ├── VercelAIGatewayModelMetadataDirectoryTest.php
│           └── VercelAIGatewayTextGenerationModelTest.php
└── Integration/
    └── AdminSettingsPageTest.php
```

Recommended priorities for the first batch of tests:

1. **`VercelAIGatewayModelMetadataDirectory::parseResponseToModelMetadataList()`**
   — assert that representative `/v1/models` payloads (plain text models,
   image-input models, image-output models, mixed-modality models) produce
   the expected capability + supported-option sets.
2. **`SettingsPage::sanitize_settings()`** — verifies the API key field is
   trimmed and that changing the key clears the model cache transient.
3. **Connection test handler** — mocks `wp_remote_get` to verify the
   error / success notices are queued correctly.
