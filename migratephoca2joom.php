<?php
/******************************************************************************\
**   JoomGallery  1.5.0                                                       **
**   By: JoomGallery::ProjectTeam                                             **
**   Copyright (C) 2013 - 2019 JoomGallery::ProjectTeam                       **
**   Released under GNU GPL Public License                                    **
**   License: http://www.gnu.org/copyleft/gpl.html or have a look             **
**   at administrator/components/com_joomgallery/LICENSE.TXT                  **
\******************************************************************************/

/*******************************************************************************
**   Migration of DB and Files from PhocaGallery to Joomgallery               **
**   On the fly generating of categories in db and file system                **
**   moving the images in the new categories                                  **
*******************************************************************************/

defined('_JEXEC') or die('Direct Access to this location is not allowed.');

class JoomMigratePhoca2Joom extends JoomMigration
{
  /**
   * The name of the migration
   * (should be unique)
   *
   * @var   string
   * @since 3.0
   */
  protected $migration = 'phoca2joom';

  /**
   * Starts all default migration checks.
   *
   * @param   array   $dirs         Array of directories to search for
   * @param   array   $tables       Array of database tables to search for
   * @param   string  $xml          Path to the XML-File of the required extension
   * @param   string  $min_version  minimal required version, false if no check shall be performed
   * @param   string  $min_version  maximum possible version, false if no check shall be performed
   * @return  void
   * @since   3.0
   */
  public function check($dirs = array(), $tables = array(), $xml = false, $min_version = false, $max_version = false)
  {
    $dirs         = array(JPATH_ROOT.'/images/phocagallery');
    $tables       = array('#__phocagallery',
                          '#__phocagallery_categories',
                          '#__phocagallery_img_comments',
                          '#__phocagallery_img_votes',
                          '#__phocagallery_img_votes_statistics');
    $xml          = 'components/com_phocagallery/phocagallery.xml';
    $min_version  = '4.0.0 RC';
    $max_version  = '4.999.0';

    parent::check($dirs, $tables, $xml, $min_version, $max_version);
  }

  /**
   * Main migration function
   *
   * @return  void
   * @since   3.0
   */
  protected function doMigration()
  {
    $task = $this->getTask('categories');

    switch($task)
    {
      case 'categories':
        $this->migrateCategories();
        // Break intentionally omited
      case 'rebuild':
        $this->rebuild();
        // Break intentionally omited
      case 'images':
        $this->migrateImages();
        // Break intentionally omited
      case 'comments':
        $this->migrateComments();
        // Break intentionally omited
      case 'votes':
        $this->migrateVotes();
        // Break intentionally omited
      default:
        break;
    }
  }

  /**
   * Returns the maximum category ID of PhocaGallery.
   *
   * @return  int   The maximum category ID of PhocaGallery
   * @since   3.0
   */
  protected function getMaxCategoryId()
  {
    $query = $this->_db2->getQuery(true)
          ->select('MAX(id)')
          ->from($this->_db2->quoteName('#__phocagallery_categories'));
    $this->_db2->setQuery($query);

    return $this->runQuery('loadResult', $this->_db2);
  }

  /**
   * Migrates all categories
   *
   * @return  void
   * @since   3.0
   */
  protected function migrateCategories()
  {
    $query = $this->_db2->getQuery(true)
          ->select('*')
          ->from($this->_db2->quoteName('#__phocagallery_categories'));
    $this->prepareTable($query, '#__phocagallery_categories', 'parent_id', array(0));

    while($cat = $this->getNextObject())
    {
      // Make information accessible for JoomGallery
      $cat->cid     = $cat->id;
      $cat->name    = $cat->title;
      $cat->owner   = $cat->owner_id;
      $cat->params  = '';
      $cat->alias   = null;

      /*// Search for thumbnail
      $cat->thumbnail = 0;
      if($cat->catimage)
      {
        $search_query = $this->_db->getQuery(true)
                      ->select('id')
                      ->from($this->table_images)
                      ->where('imgthumbname = '.$this->_db->quote($cat->catimage));
        $this->_db->setQuery($search_query);
        $cat->thumbnail = $this->runQuery('loadResult');
      }*/

      $this->createCategory($cat);

      $this->markAsMigrated($cat->id, 'id', '#__phocagallery_categories');

      if(!$this->checkTime())
      {
        $this->refresh();
      }
    }

    $this->resetTable('#__phocagallery_categories');
  }

  /**
   * Migrates all images
   *
   * @return  void
   * @since   3.0
   */
  function migrateImages()
  {
    // Image path
    $path = JPATH_ROOT.'/images/phocagallery/';
    
    $query = $this->_db2->getQuery(true)
          ->select('a.*, b.count, b.average')
          ->from($this->_db2->quoteName('#__phocagallery').' AS a')
          ->leftJoin($this->_db2->quoteName('#__phocagallery_img_votes_statistics').' AS b ON a.id = b.imgid');
    $this->prepareTable($query);

    while($row = $this->getNextObject())
    {
      // Make information accessible for JoomGallery
      $row->imgfilename = basename($row->filename);
      $row->imgtitle    = $row->title;
      $row->imgtext     = $row->description;
      $row->imgdate     = $row->date;
      $row->imgvotes    = $row->count;
      $row->imgvotesum  = (int) ($row->count * $row->average);
      $row->owner       = $row->userid;
      $row->params      = '';

      $this->moveAndResizeImage($row, $path.$row->filename);

      if(!$this->checkTime())
      {
        $this->refresh('images');
      }
    }

    $this->resetTable();
  }

  /**
   * Migrates all image comments
   *
   * @return  void
   * @since   3.0
   */
  function migrateComments()
  {
    $query = $this->_db2->getQuery(true)
          ->select('*')
          ->from($this->_db2->quoteName('#__phocagallery_img_comments'));
    $this->prepareTable($query);

    while($row = $this->getNextObject())
    {
      // Make information accessible for JoomGallery
      $row->cmtid = $row->id;
      $row->cmtpic = $row->imgid;
      $row->cmtdate = $row->date;
      $row->cmttext = $row->comment;
      if($row->title)
      {
        $row->cmttext = '[b]'.$row->title.'[/b]'."\n\n".$row->comment;
      }

      $this->createComment($row);

      if(!$this->checkTime())
      {
        $this->refresh('comments');
      }
    }

    $this->resetTable();
  }

  /**
   * Migrates all image vote records.
   *
   * Since votes cannot be unpublished in JoomGallery
   * only published votes will be migrated.
   *
   * @return  void
   * @since   3.0
   */
  function migrateVotes()
  {
    $query = $this->_db2->getQuery(true)
          ->select('*')
          ->from($this->_db2->quoteName('#__phocagallery_img_votes'))
          ->where('published = 1');
    $this->prepareTable($query);

    while($row = $this->getNextObject())
    {
      // Make information accessible for JoomGallery
      $row->voteid = $row->id;
      $row->picid = $row->imgid;
      $row->datevoted = $row->date;
      $row->vote = $row->rating;

      $this->createVote($row);

      if(!$this->checkTime())
      {
        $this->refresh('votes');
      }
    }

    $this->resetTable();
  }
}