<?php

namespace SilverStripe\Blog\Model;

use Page;
use PageController;
use SilverStripe\Blog\Admin\GridFieldCategorisationConfig;
use SilverStripe\Blog\Forms\GridField\GridFieldConfig_BlogPost;
use SilverStripe\CMS\Controllers\RootURLController;
use SilverStripe\Control\Controller;
use SilverStripe\Control\RSS\RSSFeed;
use SilverStripe\Core\Convert;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\ListboxField;
use SilverStripe\Forms\NumericField;
use SilverStripe\ORM\ArrayList;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\ORM\PaginatedList;
use SilverStripe\ORM\UnsavedRelationList;
use SilverStripe\Security\Group;
use SilverStripe\Security\Member;
use SilverStripe\Security\Permission;
use SilverStripe\Security\PermissionProvider;
use SilverStripe\View\Requirements;

/**
 * Blog Holder
 *
 * @package silverstripe
 * @subpackage blog
 *
 * @method HasManyList Tags() List of tags in this blog
 * @method HasManyList Categories() List of categories in this blog
 * @method ManyManyList Editors() List of editors
 * @method ManyManyList Writers() List of writers
 * @method ManyManyList Contributors() List of contributors
 */
class Blog extends Page implements PermissionProvider
{
    /**
     * Permission for user management.
     *
     * @var string
     */
    const MANAGE_USERS = 'BLOG_MANAGE_USERS';

    /**
     * If true, users assigned as editor, writer, or contributor will be automatically granted
     * CMS_ACCESS_CMSMain permission. If false, only users with this permission already may be
     * assigned.
     *
     * @config
     *
     * @var boolean
     */
    private static $grant_user_access = true;

    /**
     * Permission to either require, or grant to users assigned to work on this blog.
     *
     * @config
     *
     * @var string
     */
    private static $grant_user_permission = 'CMS_ACCESS_CMSMain';

    /**
     * Group code to assign newly granted users to.
     *
     * @config
     *
     * @var string
     */
    private static $grant_user_group = 'blog-users';

    /**
     * {@inheritDoc}
     * @var string
     */
    private static $table_name = 'Blog';

    /**
     * @var array
     */
    private static $db = array(
        'PostsPerPage' => 'Int',
    );

    /**
     * @var array
     */
    private static $has_many = array(
        'Tags' => 'SilverStripe\\Blog\\Model\\BlogTag',
        'Categories' => 'SilverStripe\\Blog\\Model\\BlogCategory',
    );

    /**
     * @var array
     */
    private static $many_many = array(
        'Editors' => 'SilverStripe\\Security\\Member',
        'Writers' => 'SilverStripe\\Security\\Member',
        'Contributors' => 'SilverStripe\\Security\\Member',
    );

    /**
     * @var array
     */
    private static $allowed_children = array(
        'SilverStripe\\Blog\\Model\\BlogPost',
    );

    /**
     * @var array
     */
    private static $extensions = array(
        'SilverStripe\\Blog\\Model\\BlogFilter',
    );

    /**
     * @var array
     */
    private static $defaults = array(
        'ProvideComments' => false,
        'PostsPerPage'    => 10
    );

    /**
     * @var string
     */
    private static $description = 'Adds a blog to your website.';

    private static $icon = 'blog/images/site-tree-icon.png';

    /**
     * {@inheritdoc}
     */
    public function getCMSFields()
    {
        Requirements::css(BLOGGER_DIR . '/css/cms.css');
        Requirements::javascript(BLOGGER_DIR . '/js/cms.js');

        $this->beforeUpdateCMSFields(function ($fields) {
            if (!$this->canEdit()) {
                return;
            }

            $categories = GridField::create(
                'Categories',
                _t('Blog.Categories', 'Categories'),
                $this->Categories(),
                GridFieldCategorisationConfig::create(
                    15,
                    $this->Categories()->sort('Title'),
                    BlogCategory::class,
                    'Categories',
                    'BlogPosts'
                )
            );

            $tags = GridField::create(
                'Tags',
                _t('Blog.Tags', 'Tags'),
                $this->Tags(),
                GridFieldCategorisationConfig::create(
                    15,
                    $this->Tags()->sort('Title'),
                    BlogTag::class,
                    'Tags',
                    'BlogPosts'
                )
            );

            /**
             * @var FieldList $fields
             */
            $fields->addFieldsToTab(
                'Root.Categorisation',
                array(
                    $categories,
                    $tags
                )
            );

            $fields->findOrMakeTab('Root.Categorisation')->addExtraClass('blog-cms-categorisation');
        });

        return parent::getCMSFields();
    }

    /**
     * {@inheritdoc}
     */
    public function canEdit($member = null)
    {
        $member = $this->getMember($member);

        if ($this->isEditor($member)) {
            return true;
        }

        return parent::canEdit($member);
    }

    /**
     * @param null|int|Member $member
     *
     * @return null|Member
     */
    protected function getMember($member = null)
    {
        if (!$member) {
            $member = Member::currentUser();
        }

        if (is_numeric($member)) {
            $member = Member::get()->byID($member);
        }

        return $member;
    }

    /**
     * Check if this member is an editor of the blog.
     *
     * @param Member $member
     *
     * @return bool
     */
    public function isEditor($member)
    {
        $isEditor = $this->isMemberOf($member, $this->Editors());
        $this->extend('updateIsEditor', $isEditor, $member);

        return $isEditor;
    }

    /**
     * Determine if the given member belongs to the given relation.
     *
     * @param Member $member
     * @param DataList $relation
     *
     * @return bool
     */
    protected function isMemberOf($member, $relation)
    {
        if (!$member || !$member->exists()) {
            return false;
        }

        if ($relation instanceof UnsavedRelationList) {
            return in_array($member->ID, $relation->getIDList());
        }

        return $relation->byID($member->ID) !== null;
    }

    /**
     * Determine the role of the given member.
     *
     * Call be called via template to determine the current user.
     *
     * @example "Hello $RoleOf($CurrentMember.ID)"
     *
     * @param int|Member $member
     *
     * @return null|string
     */
    public function RoleOf($member)
    {
        if (is_numeric($member)) {
            $member = Member::get()->byId($member);
        }

        if (!$member) {
            return null;
        }

        if ($this->isEditor($member)) {
            return _t('Blog.EDITOR', 'Editor');
        }

        if ($this->isWriter($member)) {
            return _t('Blog.WRITER', 'Writer');
        }

        if ($this->isContributor($member)) {
            return _t('Blog.CONTRIBUTOR', 'Contributor');
        }

        return null;
    }

    /**
     * Check if this member is a writer of the blog.
     *
     * @param Member $member
     *
     * @return bool
     */
    public function isWriter($member)
    {
        $isWriter = $this->isMemberOf($member, $this->Writers());
        $this->extend('updateIsWriter', $isWriter, $member);

        return $isWriter;
    }

    /**
     * Check if this member is a contributor of the blog.
     *
     * @param Member $member
     *
     * @return bool
     */
    public function isContributor($member)
    {
        $isContributor = $this->isMemberOf($member, $this->Contributors());
        $this->extend('updateIsContributor', $isContributor, $member);

        return $isContributor;
    }

    /**
     * {@inheritdoc}
     */
    public function canAddChildren($member = null)
    {
        $member = $this->getMember($member);

        if ($this->isEditor($member) || $this->isWriter($member) || $this->isContributor($member)) {
            return true;
        }

        return parent::canAddChildren($member);
    }

    /**
     * {@inheritdoc}
     */
    public function getSettingsFields()
    {
        $fields = parent::getSettingsFields();

        $fields->addFieldToTab(
            'Root.Settings',
            NumericField::create('PostsPerPage', _t('Blog.PostsPerPage', 'Posts Per Page'))
        );

        $members = $this->getCandidateUsers()->map()->toArray();

        $editorField = ListboxField::create('Editors', 'Editors', $members)
            // ->setMultiple(true)
            ->setRightTitle('<a class="toggle-description">help</a>')
            ->setDescription('
				An editor has control over specific Blogs, and all posts included within it. Short of being able to assign other editors to a blog, they are able to handle most changes to their assigned blog.<br />
				<br />
				Editors have these permissions:<br />
				<br />
				Update or publish any BlogPost in their Blog<br />
				Update or publish their Blog<br />
				Assign/unassign writers to their Blog<br />
				Assign/unassign contributors to their Blog<br />
				Assign/unassign any member as an author of a particular BlogPost
			');

        if (!$this->canEditEditors()) {
            $editorField = $editorField->performDisabledTransformation();
        }

        $writerField = ListboxField::create('Writers', 'Writers', $members)
            // ->setMultiple(true)
            ->setRightTitle('<a class="toggle-description">help</a>')
            ->setDescription('
				A writer has full control over creating, editing and publishing BlogPosts they have authored or have been assigned to. Writers are unable to edit BlogPosts to which they are not assigned.<br />
				<br />
				Writers have these permissions:<br />
				<br />
				Update or publish any BlogPost they have authored or have been assigned to<br />
				Assign/unassign any member as an author of a particular BlogPost they have authored or have been assigned to
			');

        if (!$this->canEditWriters()) {
            $writerField = $writerField->performDisabledTransformation();
        }

        $contributorField = ListboxField::create('Contributors', 'Contributors', $members)
            // ->setMultiple(true)
            ->setRightTitle('<a class="toggle-description">help</a>')
            ->setDescription('
				Contributors have the ability to create or edit BlogPosts, but are unable to publish without authorisation of an editor. They are also unable to assign other contributing authors to any of their BlogPosts.<br />
				<br />
				Contributors have these permissions:<br />
				<br />
				Update any BlogPost they have authored or have been assigned to
			');

        if (!$this->canEditContributors()) {
            $contributorField = $contributorField->performDisabledTransformation();
        }

        $fields->addFieldsToTab(
            'Root.Users',
            array(
                $editorField,
                $writerField,
                $contributorField
            )
        );

        return $fields;
    }

    /**
     * Gets the list of user candidates to be assigned to assist with this blog.
     *
     * @return SS_List
     */
    protected function getCandidateUsers()
    {
        if ($this->config()->grant_user_access) {
            $list = Member::get();
            $this->extend('updateCandidateUsers', $list);
            return $list;
        } else {
            return Permission::get_members_by_permission(
                $this->config()->grant_user_permission
            );
        }
    }

    /**
     * Determine if this user can edit the editors list.
     *
     * @param int|Member $member
     *
     * @return bool
     */
    public function canEditEditors($member = null)
    {
        $member = $this->getMember($member);

        $extended = $this->extendedCan('canEditEditors', $member);

        if ($extended !== null) {
            return $extended;
        }

        return Permission::checkMember($member, self::MANAGE_USERS);
    }

    /**
     * Determine if this user can edit writers list.
     *
     * @param int|Member $member
     *
     * @return boolean
     */
    public function canEditWriters($member = null)
    {
        $member = $this->getMember($member);

        $extended = $this->extendedCan('canEditWriters', $member);

        if ($extended !== null) {
            return $extended;
        }

        if ($this->isEditor($member)) {
            return true;
        }

        return Permission::checkMember($member, self::MANAGE_USERS);
    }

    /**
     * Determines if this user can edit the contributors list.
     *
     * @param int|Member $member
     *
     * @return boolean
     */
    public function canEditContributors($member = null)
    {
        $member = $this->getMember($member);

        $extended = $this->extendedCan('canEditContributors', $member);

        if ($extended !== null) {
            return $extended;
        }

        if ($this->isEditor($member)) {
            return true;
        }

        return Permission::checkMember($member, self::MANAGE_USERS);
    }

    /**
     * Returns BlogPosts for a given date period.
     *
     * @param int $year
     * @param null|int $month
     * @param null|int $day
     *
     * @return DataList
     */
    public function getArchivedBlogPosts($year, $month = null, $day = null)
    {
        $query = $this->getBlogPosts()->dataQuery();

        $stage = $query->getQueryParam('Versioned.stage');

        if ($stage) {
            $stage = '_' . $stage;
        }

        $query->innerJoin(
            DataObject::getSchema()->tableName(BlogPost::class),
            sprintf('"SiteTree%s"."ID" = "BlogPost%s"."ID"', $stage, $stage)
        );

        $conn = DB::getConn();

        // Filter by year
        $yearCond = $conn->formattedDatetimeClause('"BlogPost"."PublishDate"', '%Y');
        $query->where(sprintf('%s = \'%04d\'', $yearCond, Convert::raw2sql($year)));

        // Filter by month (if given)
        if ($month) {
            $monthCond = $conn->formattedDatetimeClause('"BlogPost"."PublishDate"', '%m');
            $query->where(sprintf('%s = \'%02d\'', $monthCond, Convert::raw2sql($month)));

            if ($day) {
                $dayCond = $conn->formattedDatetimeClause('"BlogPost"."PublishDate"', '%d');
                $query->where(sprintf('%s = \'%02d\'', $dayCond, Convert::raw2sql($day)));
            }
        }


        return $this->getBlogPosts()->setDataQuery($query);
    }

    /**
     * Return blog posts.
     *
     * @return DataList of BlogPost objects
     */
    public function getBlogPosts()
    {
        $blogPosts = BlogPost::get()->filter('ParentID', $this->ID);

        $this->extend('updateGetBlogPosts', $blogPosts);

        return $blogPosts;
    }

    /**
     * Get a link to a Member profile.
     *
     * @param string $urlSegment
     *
     * @return string
     */
    public function ProfileLink($urlSegment)
    {
        $baseLink = $this->Link();
        if ($baseLink === '/') {
            // Handle homepage blogs
            $baseLink = RootURLController::get_homepage_link();
        }

        return Controller::join_links($baseLink, 'profile', $urlSegment);
    }

    /**
     * This sets the title for our gridfield.
     *
     * @return string
     */
    public function getLumberjackTitle()
    {
        return _t('Blog.LumberjackTitle', 'Blog Posts');
    }

    /**
     * This overwrites lumberjacks default gridfield config.
     *
     * @return GridFieldConfig
     */
    public function getLumberjackGridFieldConfig()
    {
        return GridFieldConfig_BlogPost::create();
    }

    /**
     * {@inheritdoc}
     */
    public function providePermissions()
    {
        return array(
            Blog::MANAGE_USERS => array(
                'name' => _t(
                    'Blog.PERMISSION_MANAGE_USERS_DESCRIPTION',
                    'Manage users for individual blogs'
                ),
                'help' => _t(
                    'Blog.PERMISSION_MANAGE_USERS_HELP',
                    'Allow assignment of Editors, Writers, or Contributors to blogs'
                ),
                'category' => _t('Blog.PERMISSIONS_CATEGORY', 'Blog permissions'),
                'sort' => 100
            )
        );
    }

    /**
     * {@inheritdoc}
     */
    protected function onBeforeWrite()
    {
        parent::onBeforeWrite();
        $this->assignGroup();
    }

    /**
     * Assign users as necessary to the blog group.
     */
    protected function assignGroup()
    {
        if (!$this->config()->grant_user_access) {
            return;
        }

        $group = $this->getUserGroup();

        // Must check if the method exists or else an error occurs when changing page type
        if ($this->hasMethod('Editors')) {
            foreach (array($this->Editors(), $this->Writers(), $this->Contributors()) as $levels) {
                foreach ($levels as $user) {
                    if (!$user->inGroup($group)) {
                        $user->Groups()->add($group);
                    }
                }
            }
        }
    }

    /**
     * Gets or creates the group used to assign CMS access.
     *
     * @return Group
     */
    protected function getUserGroup()
    {
        $code = $this->config()->grant_user_group;

        $group = Group::get()->filter('Code', $code)->first();

        if ($group) {
            return $group;
        }

        $group = Group::create();
        $group->Title = 'Blog users';
        $group->Code = $code;

        $group->write();

        $permission = Permission::create();
        $permission->Code = $this->config()->grant_user_permission;

        $group->Permissions()->add($permission);

        return $group;
    }
}
