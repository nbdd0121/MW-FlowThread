var deleted = mw.config.get('commentfilter') === 'deleted' || mw.config.get('commentfilter') == 'spam';

function createThread(post) {
	var thread = new Thread();
	var object = thread.object;

	thread.init(post);

	// Enhance the username by adding page title
	var pageLink = wrapPageLink(post.title, post.title);
	object.find('.comment-user').html(
		mw.msg('flowthread-ui-user-post-on-page', object.find('.comment-user').html(), pageLink));

	thread.addButton('like', mw.msg('flowthread-ui-like') + '(' + post.like + ')', function() {
	});

	thread.addButton('report', mw.msg('flowthread-ui-report') + '(' + post.report + ')', function() {
	});

	if(!deleted){
		thread.addButton('delete', mw.msg('flowthread-ui-delete'), function() {
			thread.delete();
		});
	}else{
		thread.addButton('recover', mw.msg('flowthread-ui-recover'), function() {
			thread.recover();
		});
		thread.addButton('delete', mw.msg('flowthread-ui-erase'), function() {
			thread.erase();
		});
	}

	object.find('.comment-avatar').click(function() {
		object.toggleClass('comment-selected');
		onSelect();
	});

	return thread;
}

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
		$('.comment-container').append(createThread(item).object);
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