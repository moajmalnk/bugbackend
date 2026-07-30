-- Add Malayalam to all tester / QA CODO stress rules (qa_*).
-- Safe to re-run. Does not touch developer/project rules or acknowledgements.

UPDATE `codo_common_rules`
SET `description` = 'Test cross-platform layout rendering across Safari environments. Confirm layout structures, card radii, and shadow boundaries scale without breaking.\n\nMalayalam: Safari പരിതസ്ഥിതികളിലുടനീളം ക്രോസ്-പ്ലാറ്റ്ഫോം ലേഔട്ട് റെൻഡറിംഗ് പരിശോധിക്കുക. കാർഡ് റേഡിയസ്, ഷാഡോ ബൗണ്ടറികൾ തകരാതെ സ്കെയിൽ ചെയ്യുന്നുണ്ടെന്ന് ഉറപ്പാക്കുക.'
WHERE `phase` = 'tester' AND `rule_key` = 'qa_apple_sandbox';

UPDATE `codo_common_rules`
SET `description` = 'Stress-test button structures via continuous high-speed double and triple clicks. Ensure execution locks prevent duplicate API records.\n\nMalayalam: ബട്ടണുകളിൽ തുടർച്ചയായ ഉയർന്ന വേഗത്തിലുള്ള ഡബിൾ/ട്രിപ്പിൾ ക്ലിക്കുകൾ ചെയ്ത് സ്ട്രെസ്-ടെസ്റ്റ് ചെയ്യുക. ഡ്യൂപ്ലിക്കേറ്റ് API റെക്കോർഡുകൾ തടയുന്ന ലോക്കുകൾ ഉറപ്പാക്കുക.'
WHERE `phase` = 'tester' AND `rule_key` = 'qa_click_attack';

UPDATE `codo_common_rules`
SET `description` = 'Change interface color styles rapidly back and forth mid-form to detect and address unreadable text variables.\n\nMalayalam: ഫോം പൂരിപ്പിക്കുമ്പോൾ ഡാർക്ക്/ലൈറ്റ് തീം വേഗത്തിൽ മാറ്റി വായിക്കാൻ കഴിയാത്ത ടെക്സ്റ്റ്/കോൺട്രാസ്റ്റ് പ്രശ്നങ്ങൾ കണ്ടെത്തുക.'
WHERE `phase` = 'tester' AND `rule_key` = 'qa_theme_interruption';

UPDATE `codo_common_rules`
SET `description` = 'Open form modals, alter form field strings, and simulate a layout close command. Verify warning safeguards capture user context safely.\n\nMalayalam: ഫോം മോഡലുകൾ തുറന്ന് ഫീൽഡുകൾ മാറ്റി ക്ലോസ് ചെയ്യാൻ ശ്രമിക്കുക. Unsaved Changes വാണിംഗ് യൂസർ കോൺടെക്സ്റ്റ് സുരക്ഷിതമായി പിടിക്കുന്നുണ്ടോ എന്ന് പരിശോധിക്കുക.'
WHERE `phase` = 'tester' AND `rule_key` = 'qa_input_interception';

UPDATE `codo_common_rules`
SET `description` = 'Simulate empty or empty-result states across relational data blocks. Confirm descriptive empty placeholder messaging handles the viewport safely.\n\nMalayalam: ശൂന്യ/എംപ്റ്റി-റിസൾട്ട് സ്റ്റേറ്റുകൾ സിമുലേറ്റ് ചെയ്യുക. വിവരണാത്മക എംപ്റ്റി പ്ലേസ്ഹോൾഡർ മെസേജിംഗ് വ്യൂപോർട്ട് സുരക്ഷിതമായി കൈകാര്യം ചെയ്യുന്നുണ്ടെന്ന് ഉറപ്പാക്കുക.'
WHERE `phase` = 'tester' AND `rule_key` = 'qa_empty_array';

UPDATE `codo_common_rules`
SET `description` = 'Attempt long string pastes (100+ digit entries) in phone inputs. Confirm truncation rules drop unnecessary data inputs seamlessly.\n\nMalayalam: ഫോൺ ഇൻപുട്ടുകളിൽ 100+ ഡിജിറ്റ് സ്ട്രിംഗ് പേസ്റ്റ് ചെയ്യുക. അനാവശ്യ ഡാറ്റ ട്രങ്കേറ്റ് ചെയ്ത് ഡ്രോപ്പ് ചെയ്യുന്നുണ്ടെന്ന് ഉറപ്പാക്കുക.'
WHERE `phase` = 'tester' AND `rule_key` = 'qa_boundary_expansion';

UPDATE `codo_common_rules`
SET `description` = 'Drop network visibility mid-action or check server error routing. Confirm immediate user notifications via interactive Toast alerts.\n\nMalayalam: ആക്ഷൻ നടക്കുമ്പോൾ നെറ്റ്‌വർക്ക് ഡ്രോപ്പ് ചെയ്യുകയോ സർവർ എറർ റൂട്ടിംഗ് പരിശോധിക്കുകയോ ചെയ്യുക. Toast അലേർട്ടുകൾ ഉടൻ കാണിക്കുന്നുണ്ടെന്ന് ഉറപ്പാക്കുക.'
WHERE `phase` = 'tester' AND `rule_key` = 'qa_network_break';
