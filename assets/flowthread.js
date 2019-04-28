var canpost = mw.config.exists('canpost');
var ownpage = mw.config.exists('commentadmin') || mw.config.get('wgNamespaceNumber') === 2 && mw.config.get('wgTitle').replace('/$', '') === mw.user.id();

function createThread(post) {
  var thread = new Thread();
  var object = thread.object;
  thread.init(post);

  if (canpost) {
    thread.addButton('reply', mw.msg('flowthread-ui-reply'), function() {
      thread.reply();
    });
  }

  // User not signed in do not have right to vote
  if (mw.user.getId() !== 0) {
    var likeNum = post.like ? '(' + post.like + ')' : '';
    thread.addButton('like', mw.msg('flowthread-ui-like') + likeNum, function() {
      if (object.find('.comment-like').first().attr('liked') !== undefined) {
        thread.dislike();
      } else {
        thread.like();
      }
    });
    thread.addButton('report', mw.msg('flowthread-ui-report'), function() {
      if (object.find('.comment-report').first().attr('reported') !== undefined) {
        thread.dislike();
      } else {
        thread.report();
      }
    });
  } else if (post.like) {
    var likeMsg = mw.msg('flowthread-ui-like') + '(' + post.like + ')';
    $('<span>').addClass('comment-like').css('cursor','text').text(likeMsg).appendTo(thread.object.find('.comment-footer'));
  }

  // commentadmin-restricted and poster himself can delete comment
  if (ownpage || (post.userid && post.userid === mw.user.getId())) {
    thread.addButton('delete', mw.msg('flowthread-ui-delete'), function() {
      thread.delete();
      if ($('.comment-container-top').children('.comment-thread').length === 0) {
        $('.comment-container-top').attr('disabled', '');
      }
    });
  }

  if (post.myatt === 1) {
    object.find('.comment-like').attr('liked', '');
  } else if (post.myatt === 2) {
    object.find('.comment-report').attr('reported', '');
  }

  return thread;
}

function reloadComments(offset) {
  offset = offset || 0;
  var api = new mw.Api();
  api.get({
    action: 'flowthread',
    type: 'list',
    pageid: mw.config.get('wgArticleId'),
    offset: offset,
    utf8: '',
  }).done(function(data) {
    $('.comment-container-top').html('<div>' + mw.msg('flowthread-ui-popular') + '</div>').attr('disabled', '');
    $('.comment-container').html('');
    var canpostbak = canpost;
    canpost = false; // No reply for topped comments
    data.flowthread.popular.forEach(function(item) {
      var obj = createThread(item);
      obj.markAsPopular();
      $('.comment-container-top').removeAttr('disabled').append(obj.object);
    });
    canpost = canpostbak;
    data.flowthread.posts.forEach(function(item) {
      var obj = createThread(item);
      if (item.parentid === '') {
        $('.comment-container').append(obj.object);
      } else {
        Thread.fromId(item.parentid).appendChild(obj);
      }
    });
    pager.current = Math.floor(offset / 10);
    pager.count = Math.ceil(data.flowthread.count / 10);
    pager.repaint();

    if (location.hash.substring(0, 9) === '#comment-') {
      var hash = location.hash;
      location.replace('#');
      location.replace(hash);
    }
  });
}

function setFollowUp(postid, follow) {
  var obj = $('#comment-' + postid + ' > .comment-post');
  obj.after(follow);
}

/* Paginator support */
function Paginator() {
  this.object = $('<div class="comment-paginator"></div>');
  this.current = 0;
  this.count = 1;
}

Paginator.prototype.add = function(page) {
  var item = $('<span>' + (page + 1) + '</span>');
  if (page === this.current) {
    item.attr('current', '');
  }
  item.click(function() {
    reloadComments(page * 10);
  });
  this.object.append(item);
}

Paginator.prototype.addEllipse = function() {
  this.object.append('<span>...</span>')
}

Paginator.prototype.repaint = function() {
  this.object.html('');
  if (this.count <= 1) {
    this.object.hide();
  } else {
    this.object.show();
  }
  var pageStart = Math.max(this.current - 2, 0);
  var pageEnd = Math.min(this.current + 4, this.count - 1);
  if (pageStart !== 0) {
    this.add(0);
  }
  if (pageStart > 1) {
    this.addEllipse();
  }
  for (var i = pageStart; i <= pageEnd; i++) {
    this.add(i);
  }
  if (this.count - pageEnd > 2) {
    this.addEllipse();
  }
  if (this.count - pageEnd !== 1) {
    this.add(this.count - 1);
  }
}

var pager = new Paginator();

$('#bodyContent').after('<div class="comment-container-top" disabled></div>', '<div class="comment-container"></div>', pager.object, function () {
  if (canpost) return createReplyBox(null);
  var noticeContainer = $('<div>').addClass('comment-bannotice');
  noticeContainer.html(config.CantPostNotice);
  return noticeContainer;
}());

if (mw.util.getParamValue('flowthread-page')) {
  reloadComments((parseInt(mw.util.getParamValue('flowthread-page')) - 1) * 10);
} else {
  reloadComments();
}
