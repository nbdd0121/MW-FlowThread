CREATE TABLE FlowThread (
	flowthread_id BINARY(11) NOT NULL PRIMARY KEY,
	flowthread_pageid INT(11) NOT NULL,
	flowthread_userid INT(11) NOT NULL,
	flowthread_username VARCHAR(200) NOT NULL,
	flowthread_text TEXT NOT NULL,
	flowthread_parentid BINARY(11),
	flowthread_status INT(11) NOT NULL,
	flowthread_like INT(11) NOT NULL,
	flowthread_report INT(11) NOT NULL
);

CREATE TABLE FlowThreadAttitude (
	flowthread_att_id BINARY(11) NOT NULL,
	flowthread_att_type INT(11) NOT NULL,
	flowthread_att_userid INT(11) NOT NULL
);
