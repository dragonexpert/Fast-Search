<?php

$plugins->add_hook("global_end", "fastsearch_search_power");
$plugins->add_hook("class_moderation_move_thread_redirect", "fastsearch_class_moderation_move_thread_redirect");

function fastsearch_info()
{
    return array(
        "name"		=> "Fast Search",
		"description"		=> "A plug-in that greatly boosts performance of large forums by improving the search engine.",
		"website"		=> "",
		"author"		=> "Mark Janssen",
		"authorsite"		=> "",
		"version"		=> "4.0",
		"guid" 			=> "",
        "compatibility"	=> "16*,18*",
        "codename" => "fastsearch",
    );
}

function fastsearch_install()
{
    global $db;
    if(!$db->field_exists("moved", "threads"))
    {
        $db->add_column("threads", "moved", "INT UNSIGNED NOT NULL DEFAULT 0");
    }
   // Not creating an index here because if the table is extremely large, it could time out.
}

function fastsearch_is_installed()
{
    global $db;
    return $db->field_exists("moved", "threads");
}

function fastsearch_activate()
{
    // Nothing is ok here
}

function fastsearch_deactivate()
{
    // Nothing is ok here.
}



function fastsearch_search_power()
{
    if(THIS_SCRIPT == "search.php")
    {
        global $headerinclude, $header, $footer, $templates, $mybb, $db, $settings, $lang, $session, $plugins, $cache, $theme;
        require_once MYBB_ROOT . "/search2.php";
        die();
    }
    return;
}

function fastsearch_uninstall()
{
    global $db;
    if($db->field_exists("moved","threads"))
    {
        $db->drop_column("threads", "moved");
    }
}

function fastsearch_class_moderation_move_thread_redirect(&$arguments)
{
    global $mybb, $db, $thread, $redirect_expire, $fid, $moderation;
    $tid = (int) $arguments['tid'];
    $new_fid = (int) $arguments['new_fid'];

    $query = $db->simple_select('threads', 'tid', "closed='moved|$tid' AND fid='$new_fid'");
				while($redirect_tid = $db->fetch_field($query, 'tid'))
				{
					$moderation->delete_thread($redirect_tid);
				}
				$changefid = array(
					"fid" => $new_fid,
				);
				$db->update_query("threads", $changefid, "tid='$tid'");
				$db->update_query("posts", $changefid, "tid='$tid'");
				// If the thread has a prefix and the destination forum doesn't accept that prefix, remove the prefix
				if($thread['prefix'] != 0)
				{
					switch($db->type)
					{
						case "pgsql":
						case "sqlite":
							$query = $db->simple_select("threadprefixes", "COUNT(*) as num_prefixes", "(','||forums||',' LIKE '%,$new_fid,%' OR forums='-1') AND pid='".$thread['prefix']."'");
							break;
						default:
							$query = $db->simple_select("threadprefixes", "COUNT(*) as num_prefixes", "(CONCAT(',',forums,',') LIKE '%,$new_fid,%' OR forums='-1') AND pid='".$thread['prefix']."'");
					}
					if($db->fetch_field($query, "num_prefixes") == 0)
					{
						$sqlarray = array(
							"prefix" => 0,
						);
						$db->update_query("threads", $sqlarray, "tid='$tid'");
					}
				}
				$threadarray = array(
					"fid" => $thread['fid'],
					"subject" => $db->escape_string($thread['subject']),
					"icon" => $thread['icon'],
					"uid" => $thread['uid'],
					"username" => $db->escape_string($thread['username']),
					"dateline" => $thread['dateline'],
					"lastpost" => $thread['lastpost'],
					"lastposteruid" => $thread['lastposteruid'],
					"lastposter" => $db->escape_string($thread['lastposter']),
					"views" => 0,
					"replies" => 0,
					"closed" => "moved|$tid",
                    "moved" => $tid,
					"sticky" => $thread['sticky'],
					"visible" => (int) $thread['visible'],
					"notes" => ''
				);
				$newtid = $db->insert_query("threads", $threadarray);
				if($redirect_expire)
				{
					$this->expire_thread($newtid, $redirect_expire);
				}
				// If we're moving back to a forum where we left a redirect, delete the rediect
				$query = $db->simple_select("threads", "tid", "closed LIKE 'moved|".(int)$tid."' AND fid='".(int)$new_fid."'");
				while($redirect_tid = $db->fetch_field($query, 'tid'))
				{
					$moderation->delete_thread($redirect_tid);
				}
                require_once MYBB_ROOT . "/inc/functions_rebuild.php";
		        rebuild_forum_counters($new_fid);
                rebuild_forum_counters($fid);
                redirect(get_thread_link($tid), $lang->redirect_threadmoved);
                die;
}

?>
