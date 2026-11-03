<?php
namespace App\Http\Controllers;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class RoleController extends Controller
{
    private function authorizeAdmin(Request $request): void { abort_unless($request->user()->role==='Super Admin'||$request->user()->hasPermission('roles.manage'),403); }
    public function index(Request $request){$this->authorizeAdmin($request);$roles=Role::withCount(['users','permissions'])->orderBy('system','desc')->orderBy('name')->paginate(20);return view('roles.index',compact('roles'));}
    public function create(Request $request){$this->authorizeAdmin($request);$role=new Role(['active'=>true]);$permissions=Permission::orderBy('module')->orderBy('name')->get()->groupBy('module');return view('roles.form',compact('role','permissions'));}
    public function edit(Request $request,Role $role){$this->authorizeAdmin($request);$role->load('permissions');$permissions=Permission::orderBy('module')->orderBy('name')->get()->groupBy('module');return view('roles.form',compact('role','permissions'));}
    public function store(Request $request){$this->authorizeAdmin($request);$data=$this->validateRole($request);$role=DB::transaction(function()use($data){$role=Role::create(['name'=>$data['name'],'slug'=>Str::slug($data['name']).'-'.Str::lower(Str::random(5)),'description'=>$data['description']??null,'system'=>false,'active'=>true]);$role->permissions()->sync($data['permissions']??[]);return $role;});return redirect()->route('roles.index')->with('success',"{$role->name} role created.");}
    public function update(Request $request,Role $role){$this->authorizeAdmin($request);$data=$this->validateRole($request,$role);if($role->system&&$role->name!==$data['name'])throw ValidationException::withMessages(['name'=>'Built-in role names cannot be changed.']);DB::transaction(function()use($role,$data){$role->update(['name'=>$data['name'],'description'=>$data['description']??null,'active'=>$role->system?true:($data['active']??false)]);$role->permissions()->sync($data['permissions']??[]);DB::table('users')->where('role_id',$role->id)->update(['role'=>$data['name']]);});return redirect()->route('roles.index')->with('success','Role permissions updated.');}
    public function destroy(Request $request,Role $role){$this->authorizeAdmin($request);abort_if($role->system,422,'Built-in roles are protected.');if($role->users()->exists())throw ValidationException::withMessages(['role'=>'Reassign this role’s users before removing it.']);$role->delete();return back()->with('success','Role removed.');}
    private function validateRole(Request $request,?Role $role=null): array{return $request->validate(['name'=>['required','string','max:80',Rule::unique('roles','name')->ignore($role?->id)],'description'=>'nullable|string|max:500','active'=>'sometimes|boolean','permissions'=>'array','permissions.*'=>'integer|distinct|exists:permissions,id']);}
}
