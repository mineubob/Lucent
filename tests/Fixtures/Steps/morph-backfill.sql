UPDATE medias SET attachable_type = 'post', attachable_id = post_id WHERE post_id IS NOT NULL;
UPDATE medias SET attachable_type = 'event', attachable_id = event_id WHERE event_id IS NOT NULL;
UPDATE medias SET attachable_type = 'profile', attachable_id = profile_id WHERE profile_id IS NOT NULL;
