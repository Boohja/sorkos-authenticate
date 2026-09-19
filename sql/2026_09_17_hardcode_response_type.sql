UPDATE auth_clients
SET settings_json = JSON_REMOVE(settings_json, '$.response_types')
WHERE settings_json IS NOT NULL
  AND JSON_CONTAINS_PATH(settings_json, 'one', '$.response_types') = 1;