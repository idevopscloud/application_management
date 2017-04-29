<?php
return [
 		'idevops_host' => 'http://192.168.99.101:8080',
		'api_key' => 'APGH0cNRd7IC4JSLDn',
		'items' => [
				'createRole' => [
						'path'=>'v1/roles',
						'method'=>'POST',
						'type' => 'REST'
				],
				'createGroup' => [
						'path'=>'v1/groups',
						'method'=>'POST',
						'type' => 'REST'
				],
				'getGroups' => [
						'path'=>'v1/groups',
						'method'=>'GET',
						'type' => 'REST'
				],
				'deleteGroups' => [
						'path'=>'v1/groups',
						'method'=>'DELETE',
						'type' => 'REST'
				],
				'getUserInfo' => [
						'path'=>'v1/user/mime',
						'method'=>'GET'
				],
				'updateGroups'=> [
						'path'=>'v1/groups',
						'method'=>'PUT',
						'type' => 'REST'
				],
				'getUserRoleGroups'=> [
						'path'=>'v1/user/role-groups',
						'method'=>'GET'
				],
				'updateRoles'=> [
						'path'=>'v1/roles',
						'method'=>'PUT',
						'type' => 'REST'
				],
				'getUserRoles'=> [
						'path'=>'v1/user/roles',
						'method'=>'GET'
				],
				'getCompany' => [
						'path'=>'third/account/companies',
						'method'=>'GET',
						'type' => 'REST'
				],
				'updateCompany' => [
						'path'=>'third/account/companies',
						'method'=>'PUT',
						'type' => 'REST'
				],
				'getRegistry' => [
						'path'=>'third/registry/registries',
						'method'=>'GET',
						'type' => 'REST'
				],
				'createRegistry' => [
						'path'=>'third/registry/registries',
						'method'=>'POST',
						'type' => 'REST'
				],
				'buildComponentImage' => [
						'path'=>'third/registry/component_image',
						'method'=>'POST'
				],
				'getRegistryPosts' => [
						'path'=>'third/registry/posts',
						'method'=>'GET',
						'type' => 'REST'
				],
				'deleteRegistryPosts' => [
						'path'=>'third/registry/posts',
						'method'=>'DELETE',
						'type' => 'REST'
				],
		]
	];
