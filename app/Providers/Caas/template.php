<?php
return array (
		'kind' => 'CaasInstance',
		'name' => $name,
		'svc_template' => array (
				'definition' => array (
						'kind' => 'Service',
						'spec' => array (
								'ports' => $ports,
								'externalIPs' => $externalIPs,
								'selector' => array (
										'name' => $name 
								) 
						),
						'apiVersion' => 'v1',
						'metadata' => array (
								'labels' => array (
										'name' => $name 
								),
								'namespace' => $namespace,
								'name' => $name 
						) 
				) 
		),
		'rc_template' => array (
				'definition' => array (
						'kind' => 'ReplicationController',
						'spec' => array (
								'selector' => array (
										'name' => $name 
								),
								'template' => array (
										'spec' => array (
												'containers' => array (
														0 => array (
																'image' => "{$image}:{$version}",
																'name' => $name,
																'resources' => array (
																		'requests' => array (
																				'memory' => $requests_memory 
																		),
																		'limits' => array (
																				'memory' => $limits_memory 
																		) 
																),
																'env' => $env
														) 
												) 
										),
										'metadata' => array (
												'labels' => array (
														'name' => $name 
												),
												'namespace' => $namespace,
												'name' => $name 
										) 
								),
								'replicas' => $replicas 
						),
						'apiVersion' => 'v1',
						'metadata' => array (
								'labels' => array (
										'name' => $name 
								),
								'namespace' => $namespace,
								'name' => $name 
						) 
				) 
		) 
);