-- Fix voice message paths from voice_messages to voice_notes

UPDATE chat_messages 
SET voice_file_path = REPLACE(voice_file_path, 'voice_messages', 'voice_notes')
WHERE voice_file_path LIKE '%voice_messages%';

-- Log the update
SELECT COUNT(*) as updated_count 
FROM chat_messages 
WHERE voice_file_path LIKE '%voice_notes%' AND message_type = 'voice';

