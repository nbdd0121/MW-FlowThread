CREATE TABLE FlowThread (
	flowthread_id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
	flowthread_pageid INT(11) NOT NULL,
	flowthread_userid INT(11) NOT NULL,
	flowthread_username VARCHAR(200) NOT NULL,
	flowthread_text TEXT NOT NULL,
	flowthread_timestamp BINARY(14) NOT NULL,
	flowthread_parentid INT(11) NOT NULL,
	flowthread_status INT(11) NOT NULL
);

CREATE TABLE FlowThreadAttitude (
	flowthread_att_id INT(11) NOT NULL,
	flowthread_att_type INT(11) NOT NULL,
	flowthread_att_userid INT(11) NOT NULL,
	flowthread_att_username VARCHAR(200) NOT NULL,
	flowthread_att_timestamp BINARY(14) NOT NULL
);
