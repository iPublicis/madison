<?php

namespace App\Models;

/**
 *  Sponsor Model.
 */
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\MessageBag;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

use App\Models\SponsorMember;
use App\Models\Role;
use App\Models\Permission;
use App\Models\User;

use Log;

class Sponsor extends Model
{
    use SoftDeletes;

    protected $dates = ['deleted_at'];

    public static $timestamp = true;
    protected $hidden = ['pivot', 'deleted_at', 'updated_at', 'created_at'];
    protected $fillable = [
        'name',
        'display_name',
        'address1',
        'address2',
        'city',
        'state',
        'postal_code',
        'phone',
        'individual',
    ];

    const STATUS_ACTIVE = 'active';
    const STATUS_PENDING = 'pending';

    const ROLE_OWNER = 'owner';
    const ROLE_EDITOR = 'editor';
    const ROLE_STAFF = 'staff';

    /**
     *  Validation Rules.
     */
    public static $rules = array(
        'name' => 'required',
        'address1' => 'required',
        'city' => 'required',
        'state' => 'required',
        'postal_code' => 'required',
        'phone' => 'required',
        'display_name' => 'required',
    );

    protected static $customMessages = array(
      'name.required'                    => 'The sponsor name is required',
      'address1.required'            => 'The sponsor address is required',
      'city.required'                    => 'The sponsor city is required',
      'state.required'                => 'The sponsor state is required',
      'postal_code.required'    => 'The sponsor postal code is required',
      'phone.required'    => 'The sponsor phone number is required',
      'display_name.required'    => 'The sponsor display name is required',
    );

    /**
     *  Constructor.
     *
     *  @param array $attributes
     *  Extends Eloquent constructor
     */
    public function __construct($attributes = array())
    {
        parent::__construct($attributes);
        $this->validationErrors = new MessageBag();
    }

    /**
     *  Save.
     *
     *  Override Eloquent save() method
     *      Runs $this->beforeSave()
     *      Unsets:
     *          * $this->validationErrors
     *          * $this->rules
     *
     *  @param array $options
     *  $return bool
     */
    public function save(array $options = array())
    {
        if (!$this->beforeSave()) {
            return false;
        }

        //Don't want Sponsor model trying to save validationErrors field.
        unset($this->validationErrors);

        return parent::save($options);
    }

    /**
     *  getErrors.
     *
     *  Returns errors from validation
     *
     *  @param void
     *
     *  @return MessageBag $this->validationErrors
     */
    public function getErrors()
    {
        return $this->validationErrors;
    }

    /**
     *  beforeSave.
     *
     *  Validates before saving.  Returns whether the Sponsor can be saved.
     *
     *  @param array $options
     *
     *  @return bool
     */
    private function beforeSave(array $options = array())
    {
        if (!$this->validate()) {
            Log::error("Unable to validate sponsor: ");
            Log::error($this->getErrors()->toArray());
            Log::error($this->attributes);

            return false;
        }

        return true;
    }

    /**
     *  Validate.
     *
     *  Validate input against merged rules
     *
     *  @param array $attributes
     *
     *  @return bool
     */
    public function validate()
    {
        $validation = Validator::make($this->attributes, static::$rules, static::$customMessages);

        if ($validation->passes()) {
            return true;
        }

        $this->validationErrors = $validation->messages();

        return false;
    }

    public static function getStatuses()
    {
        return array(static::STATUS_ACTIVE, static::STATUS_PENDING);
    }

    public static function isValidStatus($status)
    {
        switch ($status) {
            case static::STATUS_ACTIVE:
            case static::STATUS_PENDING:
                return true;
        }

        return false;
    }

    public static function isValidRole($role)
    {
        switch ($role) {
            case static::ROLE_EDITOR:
            case static::ROLE_OWNER:
            case static::ROLE_STAFF:
                return true;
        }

        return false;
    }

    public function docs()
    {
        return $this->belongsToMany('App\Models\Doc');
    }

    public function getDisplayName()
    {
        return !empty($this->display_name) ? $this->display_name : !empty($this->name) ? $this->name : "";
    }

    /**
     *  @todo is this used?  Used to be used in $this->userHasRole, but the logic there has been changed.
     */
    public function getRoleId($role)
    {
        $role = strtolower($role);

        if (!static::isValidRole($role)) {
            throw new \Exception("Invalid Role");
        }

        return "sponsor_{$this->id}_$role";
    }

    public function userHasRole($user, $role)
    {
        $sponsorMember = SponsorMember::where('sponsor_id', '=', $this->id)->where('user_id', '=', $user->id)->first();

        return $sponsorMember && $sponsorMember->role === $role;
    }

    public static function getRoles($forHtml = false)
    {
        if ($forHtml) {
            return array(
                static::ROLE_OWNER => static::ROLE_OWNER,
                static::ROLE_EDITOR => static::ROLE_EDITOR,
                static::ROLE_STAFF => static::ROLE_STAFF,
            );
        }

        return array(static::ROLE_OWNER, static::ROLE_EDITOR, static::ROLE_STAFF);
    }

    protected function getPermissionsArray()
    {
        return array(
            array(
                'name' => "sponsor_{$this->id}_create_document",
                'display_name' => "Create Documents",
            ),
            array(
                'name' => "sponsor_{$this->id}_edit_document",
                'display_name' => 'Edit Documents',
            ),
            array(
                'name' => "sponsor_{$this->id}_delete_document",
                'display_name' => "Delete Documents",
            ),
            array(
                'name' => "sponsor_{$this->id}_manage_document",
                'display_name' => "Manage Documents",
            ),
        );
    }

    public function createRbacRules()
    {
        $this->destroyRbacRules();

        $ownerRole = new Role();
        $ownerRole->name = "sponsor_{$this->id}_owner";
        $ownerRole->save();

        $permissions = $this->getPermissionsArray();

        $permIds = array();

        $permLookup = array();

        foreach ($permissions as $perm) {
            $permModel = new Permission();

            foreach ($perm as $key => $val) {
                $permModel->$key = $val;
            }

            $permModel->save();

            $permIds[] = $permModel->id;

            switch ($perm['name']) {
                case "sponsor_{$this->id}_create_document":
                    $permLookup['create'] = $permModel->id;
                    break;
                case "sponsor_{$this->id}_edit_document":
                    $permLookup['edit'] = $permModel->id;
                    break;
                case "sponsor_{$this->id}_delete_document":
                    $permLookup['delete'] = $permModel->id;
                    break;
                case "sponsor_{$this->id}_manage_document":
                    $permLookup['manage'] = $permModel->id;
                    break;
            }
        }

        $ownerRole->perms()->sync($permIds);

        $editorRole = new Role();
        $editorRole->name = "sponsor_{$this->id}_editor";
        $editorRole->save();

        $editorRole->perms()->sync(array(
            $permLookup['create'],
            $permLookup['edit'],
            $permLookup['manage'],
        ));

        $staffRole = new Role();
        $staffRole->name = "sponsor_{$this->id}_staff";
        $staffRole->save();

        $users = array(
            static::ROLE_OWNER => $this->findUsersByRole(static::ROLE_OWNER),
            static::ROLE_EDITOR => $this->findUsersByRole(static::ROLE_EDITOR),
            static::ROLE_STAFF => $this->findUsersByRole(static::ROLE_STAFF),
        );

        foreach ($users as $role => $userList) {
            foreach ($userList as $userObj) {
                switch ($role) {
                    case static::ROLE_OWNER:
                        $userObj->attachRole($ownerRole);
                        break;
                    case static::ROLE_EDITOR:
                        $userObj->attachRole($editorRole);
                        break;
                    case static::ROLE_STAFF:
                        $userObj->attachRole($staffRole);
                        break;
                }
            }
        }
    }

    public function destroyRbacRules()
    {
        $permissions = $this->getPermissionsArray();

        $members = SponsorMember::where('sponsor_id', '=', $this->id)->get();

        $roles = Role::where('name', '=', "sponsor_{$this->id}_owner")
                     ->orWhere('name', '=', "sponsor_{$this->id}_editor")
                     ->orWhere('name', '=', "sponsor_{$this->id}_staff")
                     ->get();

        foreach ($roles as $role) {
            foreach ($members as $member) {
                $user = User::where('id', '=', $member->user_id)->first();
                $user->detachRole($role);
            }
            if ($role instanceof Role) {
                $role->delete();
            }
        }

        foreach ($permissions as $permData) {
            $perm = Permission::where('name', '=', $permData['name'])->first();

            if ($perm instanceof Permission) {
                $perm->delete();
            }
        }
    }

    public function members()
    {
        return $this->hasMany('App\Models\SponsorMember');
    }

    public static function findByUserId($userId, $onlyActive = true)
    {
        $sponsorMember = static::join('sponsor_members', 'sponsors.id', '=', 'sponsor_members.sponsor_id')
                             ->where('sponsor_members.user_id', '=', $userId);

        if ($onlyActive) {
            $sponsorMember->where('sponsors.status', '=', static::STATUS_ACTIVE);
        }

        return $sponsorMember->get(array(
            'sponsors.id', 'sponsors.name', 'sponsors.address1',
            'sponsors.address2', 'sponsors.city', 'sponsors.state',
            'sponsors.postal_code', 'sponsors.phone', 'sponsors.display_name',
            'sponsors.status', 'sponsors.created_at', 'sponsors.updated_at',
            'sponsors.deleted_at', ));
    }

    public static function findByMemberId($memberId)
    {
        $sponsorMember = SponsorMember::where('id', '=', $memberId)->first();

        if (!$sponsorMember) {
            return;
        }

        return static::where('id', '=', $sponsorMember->sponsor_id)->first();
    }

    public static function isValidUserForSponsor($user_id, $sponsor_id)
    {
        $sponsor = static::where('id', '=', $sponsor_id)->first();

        if (!$sponsor) {
            throw new Exception("Invalid Sponsor ID $sponsor_id");

            return false;
        }

        $member = $sponsor->findMemberByUserId($user_id);

        if (!$member) {
            throw new Exception("Invalid Member ID");

            return false;
        }

        return true;
    }

    public function findUsersByRole($role)
    {
        if (!static::isValidRole($role)) {
            return;
        }

        $members = SponsorMember::where('role', '=', $role)
            ->where('sponsor_id', '=', $this->id)->get();

        $retval = new Collection();
        foreach ($members as $member) {
            $userModel = User::where('id', '=', $member->user_id)->first();

            if ($userModel) {
                $retval->add($userModel);
            }
        }

        return $retval;
    }

    public function findMemberByUserId($userId)
    {
        if (!isset($this->id) || empty($this->id)) {
            throw new \Exception("You must have a sponsor ID set in order to search for members");
        }

        return SponsorMember::where('user_id', '=', $userId)->where('sponsor_id', '=', $this->id)->first();
    }

    public function isSponsorOwner($userId)
    {
        $sponsorMember = $this->findMemberByUserId($userId);

        return $sponsorMember->role == static::ROLE_OWNER;
    }

    public function getMemberRole($userId)
    {
        $sponsorMember = $this->findMemberByUserId($userId);

        return $sponsorMember->role;
    }

    public function addMember($userId, $role = null)
    {
        $sponsorMember = $this->findMemberByUserId($userId);

        if (!$sponsorMember) {
            if (is_null($role)) {
                throw new \Exception("You must provide a role if adding a new member");
            }

            if (!isset($this->id) || empty($this->id)) {
                throw new \Exception("The sponsor must have a ID set in order to add a member");
            }

            $sponsorMember = new SponsorMember();
            $sponsorMember->user_id = $userId;
            $sponsorMember->role = $role;
            $sponsorMember->sponsor_id = $this->id;
        } else {
            if (!is_null($role)) {
                $sponsorMember->role = $role;
            }
        }

        $sponsorMember->save();
    }


    public static function createIndividualSponsor($userId, $input_attrs = [])
    {
        $user = User::find($userId);

        $attrs = array_merge([
            'name' => $user->fname . ' ' . $user->lname,
            'display_name' => $user->fname . ' ' . $user->lname,
            'address1' => $user->address1 || ' ',
            'address2' => $user->address2 || ' ',
            'city' => $user->city || ' ',
            'state' => $user->state || ' ',
            'postal_code' => $user->postal_code || ' ',
            'phone' => $user->phone || ' ',
            'individual' => true,
            'status' => 'pending'
        ], $input_attrs);

        $sponsor = new Sponsor($attrs);
        $sponsor->save();
        $sponsor->addMember($userId, Sponsor::ROLE_OWNER);

        return $sponsor;
    }

    public function userCanCreateDocument($user)
    {
        return $this->userHasRole($user, Sponsor::ROLE_EDITOR) || $this->userHasRole($user, Sponsor::ROLE_OWNER);
    }

    public function isActive()
    {
        return $this->status === static::STATUS_ACTIVE;
    }
}