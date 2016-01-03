var template = '<div class="comment-thread"><div class="comment-post">'
		+ '<div class="comment-avatar">'
			+ '<img src=""></img>'
		+ '</div>'
		+ '<div class="comment-body">'
			+ '<div class="comment-user"></div>'
			+ '<div class="comment-text"></div>'
		+ '<div class="comment-footer">'
			+ '<span class="comment-time"></span>'
			+ '<span class="comment-like">' + mw.msg('flowthread-ui-like') + ' <span></span></span>'
			+ '<span class="comment-report">' + mw.msg('flowthread-ui-report') + ' <span></span></span>';

var deleted = mw.config.get('commentfilter') === 'deleted' || mw.config.get('commentfilter') == 'spam';
if(deleted){
	template += '<span class="comment-recover">' + mw.msg('flowthread-ui-recover') + '</span>';
	if(mw.config.exists('commentadmin')) {
		template += '<span class="comment-delete">' + mw.msg('flowthread-ui-erase') + '</span>';
	}
}else{
	template += '<span class="comment-delete">' + mw.msg('flowthread-ui-delete') + '</span>';
}

template += '</div>'
		+ '</div></div></div>';
var extAvatar = mw.config.get('wgUseAvatar');

function getAvatar(id, username) {
    if(id===0 || !extAvatar) {
        return mw.config.get('wgDefaultAvatar');
    }else{
        return mw.util.getUrl('Special:Avatar/' + username);
    }
}

function getTimeString(time) {
	var m = moment(time).locale(mw.config.get('wgUserLanguage'));
	var diff = Date.now() - time;
	if(0 < diff && diff < 24*3600*1000) {
		return m.fromNow();
	}else{
		return m.format('LL, HH:mm:ss');
	}
}

function wrapText(text) {
	var span = $('<span/>');
	span.text(text);
	return span.wrapAll('<div/>').parent().html();
}

function wrapPageLink(page, name) {
	var link = $('<a/>');
	link.attr('href', mw.util.getUrl(page));
	link.text(name);
	return link.wrapAll('<div/>').parent().html();
}

function Thread(post) {
	var self = this;
	var object = $(template);

	this.post = post;
	this.object = object;
	$.data(object[0], 'thread', this);

	var userlink;
	if (post.userid !== 0) {
		userlink = wrapPageLink('User: ' + post.username, post.username);
	} else {
		userlink = wrapText(post.username);
	}

	var pageLink = wrapPageLink(post.title, post.title);
	object.find('.comment-user').html(mw.msg('flowthread-ui-user-post-on-page', userlink, pageLink));

	object.find('.comment-avatar img').attr('src', getAvatar(post.userid, post.username));
	
	object.find('.comment-text').html(post.text);
	object.find('.comment-time').text(getTimeString(post.timestamp*1000));

	object.find('.comment-delete').click(function() {
		self.delete(post.id);
	});    
	object.find('.comment-recover').click(function() {
		self.recover(post.id);
	});
	object.find('.comment-delete').click(function() {
		self.erase(post.id);
	});
	object.find('.comment-avatar').click(function() {
		object.toggleClass('comment-selected');
		onSelect();
	})

	object.find('.comment-like span').text('(' + post.like + ')');
	object.find('.comment-report span').text('(' + post.report + ')');
	
}

Thread.prototype.delete = function() {
	var api = new mw.Api();
	api.get({
		action: 'flowthread',
		type: 'delete',
		postid: this.post.id
	});
	this.object.remove();
};

Thread.prototype.recover = function() {
	var api = new mw.Api();
	api.get({
		action: 'flowthread',
		type: 'recover',
		postid: this.post.id
	});
	this.object.remove();
};

Thread.prototype.erase = function() {
	var api = new mw.Api();
	api.get({
		action: 'flowthread',
		type: 'erase',
		postid: this.post.id
	});
	this.object.remove();
};

Thread.remove = function(threads) {
	threads.forEach(function(t) {
		t.object.remove();
	})
};

Thread.join = function(threads) {
	return threads.map(function(t) {
		return t.post.id;
	}).join('|');
};

Thread.delete = function(threads) {
	var api = new mw.Api();
	api.get({
		action: 'flowthread',
		type: 'delete',
		postid: Thread.join(threads)
	});
	Thread.remove(threads);
};

Thread.recover = function(threads) {
	var api = new mw.Api();
	api.get({
		action: 'flowthread',
		type: 'recover',
		postid: Thread.join(threads)
	});
	Thread.remove(threads);
};

Thread.erase = function(threads) {
	var api = new mw.Api();
	api.get({
		action: 'flowthread',
		type: 'erase',
		postid: Thread.join(threads)
	});
	Thread.remove(threads);
};

function loadComments() {
	var data = mw.config.get('commentjson');
	$('.comment-container').html('');
	data.forEach(function(item) {
		$('.comment-container').append(new Thread(item).object);
	});
}

$('#bodyContent').after('<div class="comment-container"></div>');
loadComments();

function wrapButtonMsg(msg) {
	return '<button>' + mw.msg(msg) + '</button>'
}

// Setup batch actions
var selectAll = $(wrapButtonMsg('flowthread-ui-selectall'));
$("#mw-content-text").append(selectAll);
selectAll.click(function() {
	$('.comment-thread').addClass('comment-selected');
	onSelect();
});

var unselectAll = $(wrapButtonMsg('flowthread-ui-unselectall'));
$("#mw-content-text").append(unselectAll);
unselectAll.click(function() {
	$('.comment-thread').removeClass('comment-selected');
	onSelect();
});

function getSelectedThreads() {
	return Array.prototype.map.call($('.comment-selected'), function(obj) {
		return $.data(obj, 'thread');
	})
}

if(deleted) {
	var batchRecover = $(wrapButtonMsg('flowthread-ui-recover'));
	$("#mw-content-text").append(batchRecover);
	batchRecover.click(function() {
		Thread.recover(getSelectedThreads());
	});

	if(mw.config.exists('commentadmin')) {
		var batchErase = $(wrapButtonMsg('flowthread-ui-erase'));
		$("#mw-content-text").append(batchErase);
		batchErase.click(function() {
			Thread.erase(getSelectedThreads());
		});
	}
}else{
	var batchDelete = $(wrapButtonMsg('flowthread-ui-delete'));
	$("#mw-content-text").append(batchDelete);
	batchDelete.click(function() {
		Thread.delete(getSelectedThreads());
	});
}

function onSelect() {
	if($('.comment-selected').length){
		if(batchRecover) batchRecover.show();
		if(batchErase) batchErase.show();
		if(batchDelete) batchDelete.show();
		selectAll.hide();
		unselectAll.show();
	}else{
		if(batchRecover) batchRecover.hide();
		if(batchErase) batchErase.hide();
		if(batchDelete) batchDelete.hide();
		selectAll.show();
		unselectAll.hide();
	}
}
onSelect(); // Hide batch actions