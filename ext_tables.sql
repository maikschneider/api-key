CREATE TABLE tx_apikey_key (
	key_hash varchar(64) DEFAULT '' NOT NULL,
	key_prefix varchar(32) DEFAULT '' NOT NULL,
	fe_user int(11) unsigned DEFAULT '0' NOT NULL,
	be_user int(11) unsigned DEFAULT '0' NOT NULL,
	created_by int(11) unsigned DEFAULT '0' NOT NULL,
	expires_at int(11) unsigned DEFAULT '0' NOT NULL,
	last_used_at int(11) unsigned DEFAULT '0' NOT NULL,

	KEY key_hash (key_hash)
);
