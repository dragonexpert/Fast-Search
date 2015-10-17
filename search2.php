<?php

require_once MYBB_ROOT."inc/functions_post.php";
require_once MYBB_ROOT."inc/functions_search.php";
require_once MYBB_ROOT."inc/class_parser.php";
$parser = new postParser;

// Load global language phrases
$lang->load("search");

add_breadcrumb($lang->nav_search, "search.php");

switch($mybb->input['action'])
{
	case "results":
		add_breadcrumb($lang->nav_results);
		break;
	default:
		break;
}

if($mybb->usergroup['cansearch'] == 0)
{
	error_no_permission();
}

// Check if a hard limit exists.  If not, set to 1000.
if(!$mybb->settings['searchhardlimit'] || $mybb->settings['searchhardlimit'] < 1)
{
    $mybb->settings['searchhardlimit'] = 1000;
}

$now = TIME_NOW;

if($mybb->input['action'] == "results")
{
    $sid = $mybb->get_input("sid");
    $query = $db->simple_select("searchlog", "*", "sid='$sid'");
	$search = $db->fetch_array($query);
    if(!$search['sid'])
	{
		error($lang->error_invalidsearch);
	}

	$plugins->run_hooks("search_results_start");

	// Decide on our sorting fields and sorting order.
	$order = my_strtolower(htmlspecialchars($mybb->input['order']));
	$sortby = my_strtolower(htmlspecialchars($mybb->input['sortby']));
    switch($sortby)
	{
		case "replies":
			$sortfield = "t.replies";
			break;
		case "views":
			$sortfield = "t.views";
			break;
		case "subject":
			if($search['resulttype'] == "threads")
			{
				$sortfield = "t.subject";
			}
			else
			{
				$sortfield = "p.subject";
			}
			break;
		case "forum":
			$sortfield = "t.fid";
			break;
		case "starter":
			if($search['resulttype'] == "threads")
			{
				$sortfield = "t.username";
			}
			else
			{
				$sortfield = "p.username";
			}
			break;
		case "lastpost":
		default:
			if($search['resulttype'] == "threads")
			{
				$sortfield = "t.lastpost";
				$sortby = "lastpost";
			}
			else
			{
				$sortfield = "p.dateline";
				$sortby = "dateline";
			}
			break;
	}

    if($order != "asc")
	{
		$order = "desc";
		$oppsortnext = "asc";
		$oppsort = $lang->asc;
	}
	else
	{
		$oppsortnext = "desc";
		$oppsort = $lang->desc;		
	}
	if(!$mybb->settings['threadsperpage'])
	{
		$mybb->settings['threadsperpage'] = 20;
	}
    if($search['resulttype'] == "threads")
    {
        if(!$mybb->user['tpp'])
        {
            $perpage = $mybb->settings['threadsperpage'];
        }
        else
        {
            $perpage = $mybb->user['tpp'];
        }
    }
    else
    {
        if(!$mybb->user['ppp'])
        {
            $perpage = $mybb->settings['postsperpage'];
        }
        else
        {
            $perpage = $mybb->user['ppp'];
        }
    }
    if(!$perpage) // Provide a fallback
    {
        $perpage = 20;
    }
    // Figure out the current page
    if(!$mybb->input['page']|| $mybb->input['page'] <1)
    {
        $page = 1;
    }
    else
    {
        $page = (int) $mybb->input['page'];
    }

    $sorturl = "search.php?action=results&amp;sid={$sid}";
	$thread_url = "";
	$post_url = "";
	
	eval("\$orderarrow['$sortby'] = \"".$templates->get("search_orderarrow")."\";");

    $fpermissions = forum_permissions();
	
	// Inline Mod Column for moderators
	$inlinemodcol = $inlinecookie = '';
	$is_mod = $is_supermod = false;
	if($mybb->usergroup['issupermod'])
	{
		$is_supermod = true;
	}
	if($is_supermod || is_moderator())
	{
		eval("\$inlinemodcol = \"".$templates->get("search_results_inlinemodcol")."\";");
		$inlinecookie = "inlinemod_search".$sid;
		$inlinecount = 0;
		$is_mod = true;
		$return_url = 'search.php?'.htmlspecialchars_uni($_SERVER['QUERY_STRING']);
	}

    if($search['resulttype'] == "threads")
    {
        $threads = array();
        $threadcount = 0;
        if($mybb->usergroup['issupermod'])
        {
            $visible = 0;
        }
        else
        {
            $visible = 1;
        }

        $limitsql = "LIMIT " . $mybb->settings['searchhardlimit'];
        $unsearchableforums = get_unsearchable_forums();
        if($unsearchableforums)
        {
            $unsearchsql = " AND t.fid NOT IN(" . $unsearchableforums . ") ";
        }
        if($search['querycache'] != "") // Storing a query in the table
        {
            // Count and cache the threads
            $query = $db->simple_select("threads t", "tid", $search['querycache'] . " AND t.visible >= $visible $unsearchsql AND t.moved=0 ORDER BY $sortfield $order $limitsql");
        }
        else
        {
            // Results stored in table
            $query = $db->simple_select("threads t", "tid", "t.tid IN(" . $search['threads'] . ") AND t.visible >= $visible $unsearchsql AND t.moved=0 ORDER BY $sortfield $order $limitsql");
        }
        while($thread = $db->fetch_array($query))
        {
            $threads[] = $thread['tid'];
        }
        if($db->num_rows($query) == 0)
        {
            error($lang->error_nosearchresults);
        }
        // Count the array to figure out how many results.
        $total = count($threads);
        $pages = ceil($total / $perpage);
        if($page > $pages)
        {
            $page = $pages;
        }
        $mybb->input['page'] = $page;


        $threadarraystart = $page * $perpage - $perpage;
        $threadarray = array_slice($threads, $threadarraystart, $perpage);
        $tidlist = implode(",", $threadarray);

        $query = $db->query("
			SELECT t.*, u.username AS userusername, p.displaystyle AS threadprefix
			FROM ".TABLE_PREFIX."threads t
			LEFT JOIN ".TABLE_PREFIX."users u ON (u.uid=t.uid)
			LEFT JOIN ".TABLE_PREFIX."threadprefixes p ON (p.pid=t.prefix)
			WHERE t.tid IN(" . $tidlist . ")
			ORDER BY $sortfield $order");

        $thread_cache = array();
		while($thread = $db->fetch_array($query))
		{
			$thread_cache[$thread['tid']] = $thread;
		}
		$thread_ids = implode(",", array_keys($thread_cache));
		
		if(empty($thread_ids))
		{
			error($lang->error_nosearchresults);
		}

		if(!$mybb->settings['maxmultipagelinks'])
		{
			$mybb->settings['maxmultipagelinks'] = 5;
		}

        foreach($thread_cache as $thread)
		{
			$bgcolor = alt_trow();
			$folder = '';
			$prefix = '';
			
			// Unapproved colour
			if(!$thread['visible'])
			{
				$bgcolor = 'trow_shaded';
			}

			if($thread['userusername'])
			{
				$thread['username'] = $thread['userusername'];
			}
			$thread['profilelink'] = build_profile_link($thread['username'], $thread['uid']);
			
			// If this thread has a prefix, insert a space between prefix and subject
			if($thread['prefix'] != 0)
			{
				$thread['threadprefix'] .= '&nbsp;';
			}
			
			$thread['subject'] = $parser->parse_badwords($thread['subject']);
			$thread['subject'] = htmlspecialchars_uni($thread['subject']);

			if($icon_cache[$thread['icon']])
			{
				$posticon = $icon_cache[$thread['icon']];
				$icon = "<img src=\"".$posticon['path']."\" alt=\"".$posticon['name']."\" />";
			}
			else
			{
				$icon = "&nbsp;";
			}
			if($thread['poll'])
			{
				$prefix = $lang->poll_prefix;
			}
				
			// Determine the folder
			$folder = '';
			$folder_label = '';
			if($thread['dot_icon'])
			{
				$folder = "dot_";
				$folder_label .= $lang->icon_dot;
			}
			$gotounread = '';
			$isnew = 0;
			$donenew = 0;
			$last_read = 0;
			
			if($mybb->settings['threadreadcut'] > 0 && $mybb->user['uid'])
			{
				$forum_read = $readforums[$thread['fid']];
			
				$read_cutoff = TIME_NOW-$mybb->settings['threadreadcut']*60*60*24;
				if($forum_read == 0 || $forum_read < $read_cutoff)
				{
					$forum_read = $read_cutoff;
				}
			}
			else
			{
				$forum_read = $forumsread[$thread['fid']];
			}
			
			if($mybb->settings['threadreadcut'] > 0 && $mybb->user['uid'] && $thread['lastpost'] > $forum_read)
			{
				if($thread['lastread'])
				{
					$last_read = $thread['lastread'];
				}
				else
				{
					$last_read = $read_cutoff;
				}
			}
			else
			{
				$last_read = my_get_array_cookie("threadread", $thread['tid']);
			}
	
			if($forum_read > $last_read)
			{
				$last_read = $forum_read;
			}

			if($thread['lastpost'] > $last_read && $last_read)
			{
				$folder .= "new";
				$new_class = "subject_new";
				$folder_label .= $lang->icon_new;
				$thread['newpostlink'] = get_thread_link($thread['tid'], 0, "newpost").$highlight;
				eval("\$gotounread = \"".$templates->get("forumdisplay_thread_gotounread")."\";");
				$unreadpost = 1;
			}
			else
			{
				$new_class = 'subject_old';
				$folder_label .= $lang->icon_no_new;
			}

			if($thread['replies'] >= $mybb->settings['hottopic'] || $thread['views'] >= $mybb->settings['hottopicviews'])
			{
				$folder .= "hot";
				$folder_label .= $lang->icon_hot;
			}
			if($thread['closed'] == 1)
			{
				$folder .= "lock";
				$folder_label .= $lang->icon_lock;
			}
			$folder .= "folder";
			
			if(!$mybb->settings['postsperpage'])
			{
				$mybb->settings['postperpage'] = 20;
			}

			$thread['pages'] = 0;
			$thread['multipage'] = '';
			$threadpages = '';
			$morelink = '';
			$thread['posts'] = $thread['replies'] + 1;
			if(is_moderator($thread['fid']))
			{
				$thread['posts'] += $thread['unapprovedposts'];
			}
			if($thread['posts'] > $mybb->settings['postsperpage'])
			{
				$thread['pages'] = $thread['posts'] / $mybb->settings['postsperpage'];
				$thread['pages'] = ceil($thread['pages']);
				if($thread['pages'] > $mybb->settings['maxmultipagelinks'])
				{
					$pagesstop = $mybb->settings['maxmultipagelinks'] - 1;
					$page_link = get_thread_link($thread['tid'], $thread['pages']).$highlight;
					eval("\$morelink = \"".$templates->get("forumdisplay_thread_multipage_more")."\";");
				}
				else
				{
					$pagesstop = $thread['pages'];
				}
				for($i = 1; $i <= $pagesstop; ++$i)
				{
					$page_link = get_thread_link($thread['tid'], $i).$highlight;
					eval("\$threadpages .= \"".$templates->get("forumdisplay_thread_multipage_page")."\";");
				}
				eval("\$thread['multipage'] = \"".$templates->get("forumdisplay_thread_multipage")."\";");
			}
			else
			{
				$threadpages = '';
				$morelink = '';
				$thread['multipage'] = '';
			}
			$lastpostdate = my_date($mybb->settings['dateformat'], $thread['lastpost']);
			$lastposttime = my_date($mybb->settings['timeformat'], $thread['lastpost']);
			$lastposter = $thread['lastposter'];
			$thread['lastpostlink'] = get_thread_link($thread['tid'], 0, "lastpost");
			$lastposteruid = $thread['lastposteruid'];
			$thread_link = get_thread_link($thread['tid']);

			// Don't link to guest's profiles (they have no profile).
			if($lastposteruid == 0)
			{
				$lastposterlink = $lastposter;
			}
			else
			{
				$lastposterlink = build_profile_link($lastposter, $lastposteruid);
			}

			$thread['replies'] = my_number_format($thread['replies']);
			$thread['views'] = my_number_format($thread['views']);

			if($forumcache[$thread['fid']])
			{
				$thread['forumlink'] = "<a href=\"".get_forum_link($thread['fid'])."\">".$forumcache[$thread['fid']]['name']."</a>";
			}
			else
			{
				$thread['forumlink'] = "";
			}

			// If this user is the author of the thread and it is not closed or they are a moderator, they can edit
			if(($thread['uid'] == $mybb->user['uid'] && $thread['closed'] != 1 && $mybb->user['uid'] != 0 && $fpermissions[$thread['fid']]['caneditposts'] == 1) || is_moderator($thread['fid'], "caneditposts"))
			{
				$inline_edit_class = "subject_editable";
			}
			else
			{
				$inline_edit_class = "";
			}
			$load_inline_edit_js = 1;

			// If this thread has 1 or more attachments show the papperclip
			if($thread['attachmentcount'] > 0)
			{
				if($thread['attachmentcount'] > 1)
				{
					$attachment_count = $lang->sprintf($lang->attachment_count_multiple, $thread['attachmentcount']);
				}
				else
				{
					$attachment_count = $lang->attachment_count;
				}

				eval("\$attachment_count = \"".$templates->get("forumdisplay_thread_attachment_count")."\";");
			}
			else
			{
				$attachment_count = '';
			}

			$inline_edit_tid = $thread['tid'];
			
			// Inline thread moderation
			$inline_mod_checkbox = '';
			if($is_supermod || is_moderator($thread['fid']))
			{
				eval("\$inline_mod_checkbox = \"".$templates->get("search_results_threads_inlinecheck")."\";");
			}
			elseif($is_mod)
			{
				eval("\$inline_mod_checkbox = \"".$templates->get("search_results_threads_nocheck")."\";");
			}

			$plugins->run_hooks("search_results_thread");
			eval("\$results .= \"".$templates->get("search_results_threads_thread")."\";");
		}
		if(!$results)
		{
			error($lang->error_nosearchresults);
		}
		else
		{
			if($load_inline_edit_js == 1)
			{
				eval("\$inline_edit_js = \"".$templates->get("forumdisplay_threadlist_inlineedit_js")."\";");
			}
		}

		$multipage = multipage($total, $perpage, $page, "search.php?action=results&amp;sid=$sid&amp;sortby=$sortby&amp;order=$order&amp;uid=".$mybb->input['uid']);
		if($upper > $total)
		{
			$upper = $total;
		}
		
		// Inline Thread Moderation Options
		if($is_mod)
		{
			// If user has moderation tools available, prepare the Select All feature
			$lang->page_selected = $lang->sprintf($lang->page_selected, count($thread_cache));
			$lang->all_selected = $lang->sprintf($lang->all_selected, intval($threadcount));
			$lang->select_all = $lang->sprintf($lang->select_all, intval($threadcount));
			eval("\$selectall = \"".$templates->get("search_threads_inlinemoderation_selectall")."\";");
			
			$customthreadtools = '';
			switch($db->type)
			{
				case "pgsql":
				case "sqlite":
					$query = $db->simple_select("modtools", "tid, name", "type='t' AND (','||forums||',' LIKE '%,-1,%' OR forums='')");
					break;
				default:
					$query = $db->simple_select("modtools", "tid, name", "type='t' AND (CONCAT(',',forums,',') LIKE '%,-1,%' OR forums='')");
			}
			
			while($tool = $db->fetch_array($query))
			{
				eval("\$customthreadtools .= \"".$templates->get("search_results_threads_inlinemoderation_custom_tool")."\";");
			}
			// Build inline moderation dropdown
			if(!empty($customthreadtools))
			{
				eval("\$customthreadtools = \"".$templates->get("search_results_threads_inlinemoderation_custom")."\";");
			}
			eval("\$inlinemod = \"".$templates->get("search_results_threads_inlinemoderation")."\";");
		}
		
		$plugins->run_hooks("search_results_end");
		
		eval("\$searchresults = \"".$templates->get("search_results_threads")."\";");
		output_page($searchresults);

    } // End results shown as threads
    if($search['resulttype'] == "posts")
    {
        if(!$search['posts'])
		{
			error($lang->error_nosearchresults);
		}

        $limitsql = "LIMIT " . $mybb->settings['searchhardlimit'];

        if($mybb->usergroup['issupermod'])
        {
            $visible = 0;
        }
        else
        {
            $visible = 1;
        }

        $postarray = array();   $threadarray = array();
        $unsearchableforums = get_unsearchable_forums();
        if($unsearchableforums)
        {
            $unsearchsql = " AND t.fid NOT IN(" . $unsearchableforums . ") ";
        }

        if($search['querycache'] != "")
        {
            $querycache = " AND " . $search['querycache'];
        


        	$query = $db->query("SELECT p.pid, t.tid FROM " . TABLE_PREFIX . "posts p
        	LEFT JOIN " . TABLE_PREFIX . "threads t ON(p.tid=t.tid)
        	WHERE p.visible >= $visible AND t.visible >= $visible $unsearchsql AND t.moved = 0 $querycache ORDER BY $sortfield $order $limitsql");
	}
	else
	{
		$query = $db->query("SELECT p.pid, t.tid FROM " . TABLE_PREFIX . "posts p
	        LEFT JOIN " . TABLE_PREFIX . "threads t ON(p.tid=t.tid)
        	WHERE p.visible >= $visible AND t.visible >= $visible $unsearchsql AND t.moved = 0 AND p.pid IN(" . $search['posts'] . ") ORDER BY $sortfield $order $limitsql");
	}

        while($post = $db->fetch_array($query))
        {
            $postarray[] = $post['pid'];
            $threadarray[] = $post['tid'];
        }
        if($db->num_rows($query) == 0)
        {
            error($lang->error_nosearchresults);
        }

        $total = count($postarray);
        $pages = ceil($total / $perpage);
        if($page > $pages)
        {
            $page = $pages;
        }
        $postarraystart = $page * $perpage - $perpage;

        $posts = array_slice($postarray, $postarraystart, $perpage);
        $pidlist = implode(",", $posts);
        $multipage = multipage($total, $perpage, $page, "search.php?action=results&amp;sid=".htmlspecialchars_uni($mybb->input['sid'])."&amp;sortby=$sortby&amp;order=$order&amp;uid=".$mybb->input['uid']);

        $query = $db->query("
			SELECT p.*, u.username AS userusername, t.subject AS thread_subject, t.replies AS thread_replies, t.views AS thread_views, t.lastpost AS thread_lastpost, t.closed AS thread_closed, t.uid as thread_uid
			FROM ".TABLE_PREFIX."posts p
			LEFT JOIN ".TABLE_PREFIX."threads t ON (t.tid=p.tid)
			LEFT JOIN ".TABLE_PREFIX."users u ON (u.uid=p.uid)
			WHERE p.pid IN ($pidlist)
			ORDER BY $sortfield $order");
        while($post = $db->fetch_array($query))
		{
			$bgcolor = alt_trow();
			if(!$post['visible'])
			{
				$bgcolor = 'trow_shaded';
			}
			if($post['userusername'])
			{
				$post['username'] = $post['userusername'];
			}
			$post['profilelink'] = build_profile_link($post['username'], $post['uid']);
			$post['subject'] = $parser->parse_badwords($post['subject']);
			$post['thread_subject'] = $parser->parse_badwords($post['thread_subject']);
			$post['thread_subject'] = htmlspecialchars_uni($post['thread_subject']);

			if($icon_cache[$post['icon']])
			{
				$posticon = $icon_cache[$post['icon']];
				$icon = "<img src=\"".$posticon['path']."\" alt=\"".$posticon['name']."\" />";
			}
			else
			{
				$icon = "&nbsp;";
			}

			if($forumcache[$thread['fid']])
			{
				$post['forumlink'] = "<a href=\"".get_forum_link($post['fid'])."\">".$forumcache[$post['fid']]['name']."</a>";
			}
			else
			{
				$post['forumlink'] = "";
			}
			// Determine the folder
			$folder = '';
			$folder_label = '';
			$gotounread = '';
			$isnew = 0;
			$donenew = 0;
			$last_read = 0;
			$post['thread_lastread'] = $readthreads[$post['tid']];

			if($mybb->settings['threadreadcut'] > 0 && $mybb->user['uid'])
			{
				$forum_read = $readforums[$post['fid']];
			
				$read_cutoff = TIME_NOW-$mybb->settings['threadreadcut']*60*60*24;
				if($forum_read == 0 || $forum_read < $read_cutoff)
				{
					$forum_read = $read_cutoff;
				}
			}
			else
			{
				$forum_read = $forumsread[$post['fid']];
			}

			if($mybb->settings['threadreadcut'] > 0 && $mybb->user['uid'] && $post['thread_lastpost'] > $forum_read)
			{
				$cutoff = TIME_NOW-$mybb->settings['threadreadcut']*60*60*24;
				if($post['thread_lastpost'] > $cutoff)
				{
					if($post['thread_lastread'])
					{
						$last_read = $post['thread_lastread'];
					}
					else
					{
						$last_read = 1;
					}
				}
			}

			if($dot_icon[$post['tid']])
			{
				$folder = "dot_";
				$folder_label .= $lang->icon_dot;
			}

			if(!$last_read)
			{
				$readcookie = $threadread = my_get_array_cookie("threadread", $post['tid']);
				if($readcookie > $forum_read)
				{
					$last_read = $readcookie;
				}
				elseif($forum_read > $mybb->user['lastvisit'])
				{
					$last_read = $forum_read;
				}
				else
				{
					$last_read = $mybb->user['lastvisit'];
				}
			}

			if($post['thread_lastpost'] > $last_read && $last_read)
			{
				$folder .= "new";
				$folder_label .= $lang->icon_new;
				eval("\$gotounread = \"".$templates->get("forumdisplay_thread_gotounread")."\";");
				$unreadpost = 1;
			}
			else
			{
				$folder_label .= $lang->icon_no_new;
			}

			if($post['thread_replies'] >= $mybb->settings['hottopic'] || $post['thread_views'] >= $mybb->settings['hottopicviews'])
			{
				$folder .= "hot";
				$folder_label .= $lang->icon_hot;
			}
			if($thread['thread_closed'] == 1)
			{
				$folder .= "lock";
				$folder_label .= $lang->icon_lock;
			}
			$folder .= "folder";

			$post['thread_replies'] = my_number_format($post['thread_replies']);
			$post['thread_views'] = my_number_format($post['thread_views']);

			if($forumcache[$post['fid']])
			{
				$post['forumlink'] = "<a href=\"".get_forum_link($post['fid'])."\">".$forumcache[$post['fid']]['name']."</a>";
			}
			else
			{
				$post['forumlink'] = "";
			}

			if(!$post['subject'])
			{
				$post['subject'] = $post['message'];
			}
			if(my_strlen($post['subject']) > 50)
			{
				$post['subject'] = htmlspecialchars_uni(my_substr($post['subject'], 0, 50)."...");
			}
			else
			{
				$post['subject'] = htmlspecialchars_uni($post['subject']);
			}
			// What we do here is parse the post using our post parser, then strip the tags from it
			$parser_options = array(
				'allow_html' => 0,
				'allow_mycode' => 1,
				'allow_smilies' => 0,
				'allow_imgcode' => 0,
				'filter_badwords' => 1
			);
			$post['message'] = strip_tags($parser->parse_message($post['message'], $parser_options));
			if(my_strlen($post['message']) > 200)
			{
				$prev = my_substr($post['message'], 0, 200)."...";
			}
			else
			{
				$prev = $post['message'];
			}
			$posted = my_date($mybb->settings['dateformat'], $post['dateline']).", ".my_date($mybb->settings['timeformat'], $post['dateline']);
			
			$thread_url = get_thread_link($post['tid']);
			$post_url = get_post_link($post['pid'], $post['tid']);
			
			// Inline post moderation
			$inline_mod_checkbox = '';
			if($is_supermod || is_moderator($post['fid']))
			{
				eval("\$inline_mod_checkbox = \"".$templates->get("search_results_posts_inlinecheck")."\";");
			}
			elseif($is_mod)
			{
				eval("\$inline_mod_checkbox = \"".$templates->get("search_results_posts_nocheck")."\";");
			}

			$plugins->run_hooks("search_results_post");
			eval("\$results .= \"".$templates->get("search_results_posts_post")."\";");
		}
		if(!$results)
		{
			error($lang->error_nosearchresults);
		}
		if($upper > $total)
		{
			$upper = $total;
		}
		
		// Inline Post Moderation Options
		if($is_mod)
		{
			// If user has moderation tools available, prepare the Select All feature
			$num_results = $db->num_rows($query);
			$lang->page_selected = $lang->sprintf($lang->page_selected, intval($num_results));
			$lang->select_all = $lang->sprintf($lang->select_all, intval($postcount));
			$lang->all_selected = $lang->sprintf($lang->page_selected, intval($postcount));
			eval("\$selectall = \"".$templates->get("search_posts_inlinemoderation_selectall")."\";");
			
			$customthreadtools = $customposttools = '';
			switch($db->type)
			{
				case "pgsql":
				case "sqlite":
					$query = $db->simple_select("modtools", "tid, name, type", "type='p' AND (','||forums||',' LIKE '%,-1,%' OR forums='')");
					break;
				default:
					$query = $db->simple_select("modtools", "tid, name, type", "type='p' AND (CONCAT(',',forums,',') LIKE '%,-1,%' OR forums='')");
			}
			
			while($tool = $db->fetch_array($query))
			{
				eval("\$customposttools .= \"".$templates->get("search_results_posts_inlinemoderation_custom_tool")."\";");
			}
			// Build inline moderation dropdown
			if(!empty($customposttools))
			{
				eval("\$customposttools = \"".$templates->get("search_results_posts_inlinemoderation_custom")."\";");
			}
			eval("\$inlinemod = \"".$templates->get("search_results_posts_inlinemoderation")."\";");
		}
		
		$plugins->run_hooks("search_results_end");

		eval("\$searchresults = \"".$templates->get("search_results_posts")."\";");
		output_page($searchresults);
	} // End post results
} // End action = results
elseif($mybb->input['action'] == "findguest")
{
	$where_sql = "uid='0'";

	$unsearchforums = get_unsearchable_forums();
	if($unsearchforums)
	{
		$where_sql .= " AND fid NOT IN ($unsearchforums)";
	}
	$inactiveforums = get_inactive_forums();
	if($inactiveforums)
	{
		$where_sql .= " AND fid NOT IN ($inactiveforums)";
	}
	
	$permsql = "";
	$onlyusfids = array();

	// Check group permissions if we can't view threads not started by us
	$group_permissions = forum_permissions();
	foreach($group_permissions as $fid => $forum_permissions)
	{
		if($forum_permissions['canonlyviewownthreads'] == 1)
		{
			$onlyusfids[] = $fid;
		}
	}
	if(!empty($onlyusfids))
	{
		$where_sql .= " AND fid NOT IN(".implode(',', $onlyusfids).")";
	}
	
	$options = array(
		'order_by' => 'dateline',
		'order_dir' => 'desc'
	);

	// Do we have a hard search limit?
	if($mybb->settings['searchhardlimit'] > 0)
	{
		$options['limit'] = intval($mybb->settings['searchhardlimit']);
	}

	$pids = '';
	$comma = '';
	$query = $db->simple_select("posts", "pid", "{$where_sql}", $options);
	while($pid = $db->fetch_field($query, "pid"))
	{
			$pids .= $comma.$pid;
			$comma = ',';
	}

	$tids = '';
	$comma = '';
	$query = $db->simple_select("threads", "tid", $where_sql);
	while($tid = $db->fetch_field($query, "tid"))
	{
			$tids .= $comma.$tid;
			$comma = ',';
	}

	$sid = md5(uniqid(microtime(), 1));
	$searcharray = array(
		"sid" => $db->escape_string($sid),
		"uid" => $mybb->user['uid'],
		"dateline" => TIME_NOW,
		"ipaddress" => $db->escape_string($session->ipaddress),
		"threads" => $db->escape_string($tids),
		"posts" => $db->escape_string($pids),
		"resulttype" => "posts",
		"querycache" => '',
		"keywords" => ''
	);
	$plugins->run_hooks("search_do_search_process");
	$db->insert_query("searchlog", $searcharray);
	redirect("search.php?action=results&sid=".$sid, $lang->redirect_searchresults);
}
elseif($mybb->input['action'] == "finduser")
{
	$where_sql = "uid='".$mybb->get_input('uid', MyBB::INPUT_INT)."'";

	$unsearchforums = get_unsearchable_forums();
	if($unsearchforums)
	{
		$where_sql .= " AND fid NOT IN ($unsearchforums)";
	}
	$inactiveforums = get_inactive_forums();
	if($inactiveforums)
	{
		$where_sql .= " AND fid NOT IN ($inactiveforums)";
	}

	$permsql = "";
	$onlyusfids = array();

	// Check group permissions if we can't view threads not started by us
	$group_permissions = forum_permissions();
	foreach($group_permissions as $fid => $forum_permissions)
	{
		if(isset($forum_permissions['canonlyviewownthreads']) && $forum_permissions['canonlyviewownthreads'] == 1)
		{
			$onlyusfids[] = $fid;
		}
	}
	if(!empty($onlyusfids))
	{
		$where_sql .= "AND ((fid IN(".implode(',', $onlyusfids).") AND uid='{$mybb->user['uid']}') OR fid NOT IN(".implode(',', $onlyusfids)."))";
	}

	$options = array(
		'order_by' => 'dateline',
		'order_dir' => 'desc'
	);

	// Do we have a hard search limit?
	if($mybb->settings['searchhardlimit'] > 0)
	{
		$options['limit'] = (int)$mybb->settings['searchhardlimit'];
	}

	$pids = '';
	$comma = '';
	$query = $db->simple_select("posts", "pid", "{$where_sql}", $options);
	while($pid = $db->fetch_field($query, "pid"))
	{
			$pids .= $comma.$pid;
			$comma = ',';
	}

	$tids = '';
	$comma = '';
	$query = $db->simple_select("threads", "tid", $where_sql);
	while($tid = $db->fetch_field($query, "tid"))
	{
			$tids .= $comma.$tid;
			$comma = ',';
	}

	$sid = md5(uniqid(microtime(), true));
	$searcharray = array(
		"sid" => $db->escape_string($sid),
		"uid" => $mybb->user['uid'],
		"dateline" => TIME_NOW,
		"ipaddress" => $db->escape_binary($session->packedip),
		"threads" => $db->escape_string($tids),
		"posts" => $db->escape_string($pids),
		"resulttype" => "posts",
		"querycache" => 'p.uid=' . $mybb->get_input("uid", MyBB::INPUT_INT),
		"keywords" => ''
	);
	$plugins->run_hooks("search_do_search_process");
	$db->insert_query("searchlog", $searcharray);
	redirect("search.php?action=results&sid=".$sid, $lang->redirect_searchresults);
}
elseif($mybb->input['action'] == "finduserthreads")
{
	$where_sql = "t.uid='".intval($mybb->input['uid'])."'";

	$unsearchforums = get_unsearchable_forums();
	if($unsearchforums)
	{
		$where_sql .= " AND t.fid NOT IN ($unsearchforums)";
	}
	$inactiveforums = get_inactive_forums();
	if($inactiveforums)
	{
		$where_sql .= " AND t.fid NOT IN ($inactiveforums)";
	}
	
	$permsql = "";
	$onlyusfids = array();

	// Check group permissions if we can't view threads not started by us
	$group_permissions = forum_permissions();
	foreach($group_permissions as $fid => $forum_permissions)
	{
		if($forum_permissions['canonlyviewownthreads'] == 1)
		{
			$onlyusfids[] = $fid;
		}
	}
	if(!empty($onlyusfids))
	{
		$where_sql .= "AND ((t.fid IN(".implode(',', $onlyusfids).") AND t.uid='{$mybb->user['uid']}') OR t.fid NOT IN(".implode(',', $onlyusfids)."))";
	}

	$sid = md5(uniqid(microtime(), 1));
	$tuid = "";
	if($mybb->input['uid'])
	{
		$tuid = intval($mybb->input['uid']);
	}
	$searcharray = array(
		"sid" => $db->escape_string($sid),
		"uid" => $mybb->user['uid'],
		"dateline" => TIME_NOW,
		"ipaddress" => $db->escape_string($session->ipaddress),
		"threads" => '',
		"posts" => '',
		"resulttype" => "threads",
		"querycache" => $db->escape_string($where_sql),
		"keywords" => ''
	);
	$plugins->run_hooks("search_do_search_process");
	$db->insert_query("searchlog", $searcharray);
	redirect("search.php?action=results&sid=".$sid."&uid=$tuid", $lang->redirect_searchresults);
}
elseif($mybb->input['action'] == "getnew")
{
	
	$where_sql = "t.lastpost >= '".$mybb->user['lastvisit']."'";

	if($mybb->input['fid'])
	{
		$where_sql .= " AND t.fid='".intval($mybb->input['fid'])."'";
	}
	else if($mybb->input['fids'])
	{
		$fids = explode(',', $mybb->input['fids']);
		foreach($fids as $key => $fid)
		{
			$fids[$key] = intval($fid);
		}
		
		if(!empty($fids))
		{
			$where_sql .= " AND t.fid IN (".implode(',', $fids).")";
		}
	}
	
	$unsearchforums = get_unsearchable_forums();
	if($unsearchforums)
	{
		$where_sql .= " AND t.fid NOT IN ($unsearchforums)";
	}
	
	$permsql = "";
	$onlyusfids = array();

	// Check group permissions if we can't view threads not started by us
	$group_permissions = forum_permissions();
	foreach($group_permissions as $fid => $forum_permissions)
	{
		if($forum_permissions['canonlyviewownthreads'] == 1)
		{
			$onlyusfids[] = $fid;
		}
	}
	if(!empty($onlyusfids))
	{
		$where_sql .= "AND ((t.fid IN(".implode(',', $onlyusfids).") AND t.uid='{$mybb->user['uid']}') OR t.fid NOT IN(".implode(',', $onlyusfids)."))";
	}

	$sid = md5(uniqid(microtime(), 1));
	$searcharray = array(
		"sid" => $db->escape_string($sid),
		"uid" => $mybb->user['uid'],
		"dateline" => TIME_NOW,
		"ipaddress" => $db->escape_string($session->ipaddress),
		"threads" => '',
		"posts" => '',
		"resulttype" => "threads",
		"querycache" => $db->escape_string($where_sql),
		"keywords" => ''
	);

	$plugins->run_hooks("search_do_search_process");
	$db->insert_query("searchlog", $searcharray);
	redirect("search.php?action=results&sid=".$sid, $lang->redirect_searchresults);
}
elseif($mybb->input['action'] == "getdaily")
{
	if($mybb->input['days'] < 1)
	{
		$days = 1;
	}
	else
	{
		$days = intval($mybb->input['days']);
	}
	$datecut = TIME_NOW-(86400*$days);

	$where_sql = "t.lastpost >='".$datecut."'";

	if($mybb->input['fid'])
	{
		$where_sql .= " AND t.fid='".intval($mybb->input['fid'])."'";
	}
	else if($mybb->input['fids'])
	{
		$fids = explode(',', $mybb->input['fids']);
		foreach($fids as $key => $fid)
		{
			$fids[$key] = intval($fid);
		}
		
		if(!empty($fids))
		{
			$where_sql .= " AND t.fid IN (".implode(',', $fids).")";
		}
	}
	
	$unsearchforums = get_unsearchable_forums();
	if($unsearchforums)
	{
		$where_sql .= " AND t.fid NOT IN ($unsearchforums)";
	}
	
	$permsql = "";
	$onlyusfids = array();

	// Check group permissions if we can't view threads not started by us
	$group_permissions = forum_permissions();
	foreach($group_permissions as $fid => $forum_permissions)
	{
		if($forum_permissions['canonlyviewownthreads'] == 1)
		{
			$onlyusfids[] = $fid;
		}
	}
	if(!empty($onlyusfids))
	{
		$where_sql .= "AND ((t.fid IN(".implode(',', $onlyusfids).") AND t.uid='{$mybb->user['uid']}') OR t.fid NOT IN(".implode(',', $onlyusfids)."))";
	}

	$sid = md5(uniqid(microtime(), 1));
	$searcharray = array(
		"sid" => $db->escape_string($sid),
		"uid" => $mybb->user['uid'],
		"dateline" => TIME_NOW,
		"ipaddress" => $db->escape_string($session->ipaddress),
		"threads" => '',
		"posts" => '',
		"resulttype" => "threads",
		"querycache" => $db->escape_string($where_sql),
		"keywords" => ''
	);

	$plugins->run_hooks("search_do_search_process");
	$db->insert_query("searchlog", $searcharray);
	redirect("search.php?action=results&sid=".$sid, $lang->redirect_searchresults);
}
elseif($mybb->input['action'] == "do_search" && $mybb->request_method == "post")
{
	$plugins->run_hooks("search_do_search_start");

	// Check if search flood checking is enabled and user is not admin
	if($mybb->settings['searchfloodtime'] > 0 && $mybb->usergroup['cancp'] != 1)
	{
		// Fetch the time this user last searched
		if($mybb->user['uid'])
		{
			$conditions = "uid='{$mybb->user['uid']}'";
		}
		else
		{
			$conditions = "uid='0' AND ipaddress=".$db->escape_binary($session->packedip);
		}
		$timecut = TIME_NOW-$mybb->settings['searchfloodtime'];
		$query = $db->simple_select("searchlog", "*", "$conditions AND dateline > '$timecut'", array('order_by' => "dateline", 'order_dir' => "DESC"));
		$last_search = $db->fetch_array($query);
		// Users last search was within the flood time, show the error
		if($last_search['sid'])
		{
			$remaining_time = $mybb->settings['searchfloodtime']-(TIME_NOW-$last_search['dateline']);
			if($remaining_time == 1)
			{
				$lang->error_searchflooding = $lang->sprintf($lang->error_searchflooding_1, $mybb->settings['searchfloodtime']);
			}
			else
			{
				$lang->error_searchflooding = $lang->sprintf($lang->error_searchflooding, $mybb->settings['searchfloodtime'], $remaining_time);
			}
			error($lang->error_searchflooding);
		}
	}
	if($mybb->get_input('showresults') == "threads")
	{
		$resulttype = "threads";
	}
	else
	{
		$resulttype = "posts";
	}

	$search_data = array(
		"keywords" => $mybb->input['keywords'],
		"author" => $mybb->get_input('author'),
		"postthread" => $mybb->get_input('postthread', MyBB::INPUT_INT),
		"matchusername" => $mybb->get_input('matchusername', MyBB::INPUT_INT),
		"postdate" => $mybb->get_input('postdate', MyBB::INPUT_INT),
		"pddir" => $mybb->get_input('pddir', MyBB::INPUT_INT),
		"forums" => $mybb->input['forums'],
		"findthreadst" => $mybb->get_input('findthreadst', MyBB::INPUT_INT),
		"numreplies" => $mybb->get_input('numreplies', MyBB::INPUT_INT),
		"threadprefix" => $mybb->get_input('threadprefix', MyBB::INPUT_ARRAY)
	);

	if(is_moderator() && !empty($mybb->input['visible']))
	{
		$search_data['visible'] = $mybb->get_input('visible', MyBB::INPUT_INT);
	}


	if($db->can_search == true)
	{
		if($mybb->settings['searchtype'] == "fulltext" && $db->supports_fulltext_boolean("posts") && $db->is_fulltext("posts"))
		{
			$search_results = perform_search_mysql_ft($search_data);
		}
		else
		{
			$search_results = perform_search_mysql($search_data);
		}
	}
	else
	{
		error($lang->error_no_search_support);
	}


	$sid = md5(uniqid(microtime(), true));
	$searcharray = array(
		"sid" => $db->escape_string($sid),
		"uid" => $mybb->user['uid'],
		"dateline" => TIME_NOW,
		"ipaddress" => $db->escape_binary($session->packedip),
		"threads" => $search_results['threads'],
		"posts" => $search_results['posts'],
		"resulttype" => $resulttype,
		"querycache" => $search_results['querycache'],
		"keywords" => $db->escape_string($mybb->input['keywords']),
	);
	$plugins->run_hooks("search_do_search_process");

	$db->insert_query("searchlog", $searcharray);

	if(my_strtolower($mybb->get_input('sortordr')) == "asc" || my_strtolower($mybb->get_input('sortordr') == "desc"))
	{
		$sortorder = $mybb->get_input('sortordr');
	}
	else
	{
		$sortorder = "desc";
	}
	$sortby = htmlspecialchars_uni($mybb->get_input('sortby'));
	if(!$sortby)
	{
		$sortby = "dateline";
	}
	$plugins->run_hooks("search_do_search_end");
	redirect("search.php?action=results&sid=".$sid."&sortby=".$sortby."&order=".$sortorder, $lang->redirect_searchresults);

}
else if($mybb->input['action'] == "thread")
{
	// Fetch thread info
	$thread = get_thread($mybb->input['tid']);
	if(!$thread['tid'] || (($thread['visible'] == 0 && !is_moderator($thread['fid'])) || $thread['visible'] < 0))
	{
		error($lang->error_invalidthread);
	}

	// Get forum info
	$forum = get_forum($thread['fid']);
	if(!$forum)
	{
		error($lang->error_invalidforum);
	}

	$forum_permissions = forum_permissions($forum['fid']);

	if($forum['open'] == 0 || $forum['type'] != "f")
	{
		error($lang->error_closedinvalidforum);
	}
	if($forum_permissions['canview'] == 0 || $forum_permissions['canviewthreads'] != 1)
	{
		error_no_permission();
	}

	$plugins->run_hooks("search_thread_start");

	// Check if search flood checking is enabled and user is not admin
	if($mybb->settings['searchfloodtime'] > 0 && $mybb->usergroup['cancp'] != 1)
	{
		// Fetch the time this user last searched
		if($mybb->user['uid'])
		{
			$conditions = "uid='{$mybb->user['uid']}'";
		}
		else
		{
			$conditions = "uid='0' AND ipaddress='".$db->escape_string($session->ipaddress)."'";
		}
		$timecut = TIME_NOW-$mybb->settings['searchfloodtime'];
		$query = $db->simple_select("searchlog", "*", "$conditions AND dateline > '$timecut'", array('order_by' => "dateline", 'order_dir' => "DESC"));
		$last_search = $db->fetch_array($query);

		// We shouldn't show remaining time if time is 0 or under.
		$remaining_time = $mybb->settings['searchfloodtime']-(TIME_NOW-$last_search['dateline']);
		// Users last search was within the flood time, show the error.
		if($last_search['sid'] && $remaining_time > 0)
		{
			if($remaining_time == 1)
			{
				$lang->error_searchflooding = $lang->sprintf($lang->error_searchflooding_1, $mybb->settings['searchfloodtime']);
			}
			else
			{
				$lang->error_searchflooding = $lang->sprintf($lang->error_searchflooding, $mybb->settings['searchfloodtime'], $remaining_time);
			}
			error($lang->error_searchflooding);
		}
	}

	$search_data = array(
		"keywords" => $mybb->input['keywords'],
		"postthread" => 1,
		"tid" => $mybb->input['tid']
	);

	if($db->can_search == true)
	{
        // Now we are going to check if a request for the same keywords has taken place within the last x minutes.  15 minutes is default.
        if(!$mybb->settings['fastsearchcache'])
        {
            $mybb->settings['fastsearchcache'] = 15;
        }
        $cutoff = TIME_NOW - 60 * $mybb->settings['fastsearchcache'];
        // Manipulate the keyword to not end in "ed" or "s"
        $keywords = $db->escape_string($mybb->input['keywords']);
        $length = strlen($keywords);
        if(substr($keywords, $length-2) == "ed")
        {
            $keywords = substr($keywords, 0, $length -2);   
        }
        else if(substr($keywords, $length-1) == "s")
        {
            $keywords = substr($keywords,0, $length -1);
        }
        else
        {
            $keywords = $keywords;
        }
        $keywords = $db->escape_string($keywords);
        // New in 3.0 cache keywords.
        $quickquery = $db->simple_select("searchlog", "sid", "dateline>=$cutoff AND keywords='$keywords'", array("order_by" => "dateline", "order_dir" => "DESC", "limit" => 1));
        $results = $db->fetch_array($quickquery);
        if($results['sid']) // We have a cache so load it.
        {
            redirect("search.php?action=results&sid=".$results['sid'], $lang->redirect_searchresults);
            exit;
        }
		if($mybb->settings['searchtype'] == "fulltext" && $db->supports_fulltext_boolean("posts") && $db->is_fulltext("posts"))
		{
			$search_results = perform_search_mysql_ft($search_data);
		}
		else
		{
			$search_results = perform_search_mysql($search_data);
		}
	}
	else
	{
		error($lang->error_no_search_support);
	}
	$sid = md5(uniqid(microtime(), 1));
	$searcharray = array(
		"sid" => $db->escape_string($sid),
		"uid" => $mybb->user['uid'],
		"dateline" => TIME_NOW,
		"ipaddress" => $db->escape_string($session->ipaddress),
		"threads" => $search_results['threads'],
		"posts" => $search_results['posts'],
		"resulttype" => 'posts',
		"querycache" => $search_results['querycache'],
		"keywords" => $db->escape_string($mybb->input['keywords'])
	);
	$plugins->run_hooks("search_thread_process");

	$db->insert_query("searchlog", $searcharray);

	$plugins->run_hooks("search_do_search_end");
	redirect("search.php?action=results&sid=".$sid, $lang->redirect_searchresults);
}
else
{
	$plugins->run_hooks("search_start");
	$srchlist = make_searchable_forums("", $fid);
	$prefixselect = build_prefix_select('all', 'any', 1);
	
	$rowspan = 5;
	
	if(is_moderator())
	{
		$rowspan += 2;
		eval("\$moderator_options = \"".$templates->get("search_moderator_options")."\";");
	}
	
	$plugins->run_hooks("search_end");
	
	eval("\$search = \"".$templates->get("search")."\";");
	output_page($search);
}    
?>
