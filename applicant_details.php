<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    exit(0);
}

// Include database connection
try {
    require_once 'db_connection.php';
    
    // Check if $conn is available and connected
    if (!isset($conn) || $conn === null) {
        throw new Exception('Database connection failed');
    }
    
    if ($conn->connect_error) {
        throw new Exception('Database connection error: ' . $conn->connect_error);
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection error: ' . $e->getMessage()]);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true);

// Check if this is an applicant details request
$isApplicantDetailsRequest = isset($_GET['type']) && $_GET['type'] === 'empdetails';

try {
    switch ($method) {
        case 'GET':
            if ($isApplicantDetailsRequest) {
                handleApplicantDetailsGet($conn);
            } else {
                handleGet($conn);
            }
            break;
        case 'POST':
            if ($isApplicantDetailsRequest) {
                handleApplicantDetailsPost($conn, $input);
            } else {
                handlePost($conn, $input);
            }
            break;
        case 'PUT':
            if ($isApplicantDetailsRequest) {
                handleApplicantDetailsPut($conn, $input);
            } else {
                handlePut($conn, $input);
            }
            break;
        case 'DELETE':
            handleDelete($conn, $input);
            break;
        default:
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            break;
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

// FUNCTIONS FOR APPLICANT DETAILS
function handleApplicantDetailsGet($conn) {
    $user_id = $_GET['user_id'] ?? null;
    
    if (!$user_id) {
        http_response_code(400);
        echo json_encode(['error' => 'User ID is required']);
        return;
    }
    
    try {
        // FIXED: Get applicant data including created_at and position from applicantlist
        $sql = "SELECT 
                    ua.id,
                    ua.firstName,
                    ua.lastName,
                    ua.email,
                    ua.profile_image,
                    ua.created_at,
                    al.middle_name,
                    al.contact_number,
                    al.gender,
                    al.position,
                    al.skills,
                    al.work_experience,
                    al.education as al_education,
                    al.created_at as application_date,
                    up.background_position,
                    up.background_company,
                    up.background_year,
                    up.background_description,
                    up.skills_traits,
                    up.education_level,
                    up.school_name,
                    up.school_address,
                    up.field_study,
                    up.start_year,
                    up.end_year
                FROM useraccounts ua 
                LEFT JOIN applicantlist al ON ua.id = al.app_id
                LEFT JOIN user_profiles up ON ua.id = up.user_id
                WHERE ua.id = ? AND ua.role = 'applicant'";
                
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new Exception('SQL prepare failed: ' . $conn->error);
        }
        
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $applicant = $result->fetch_assoc();
        
        if (!$applicant) {
            http_response_code(404);
            echo json_encode(['error' => 'Applicant not found']);
            return;
        }
        
        // Parse background experience data - prioritize user_profiles, fallback to applicantlist
        $backgroundExperience = [];
        
        // First try user_profiles data
        if (!empty($applicant['background_position'])) {
            $positions = explode('|', $applicant['background_position'] ?? '');
            $companies = explode('|', $applicant['background_company'] ?? '');
            $years = explode('|', $applicant['background_year'] ?? '');
            $descriptions = explode('|', $applicant['background_description'] ?? '');
            
            for ($i = 0; $i < count($positions); $i++) {
                if (!empty($positions[$i])) {
                    $backgroundExperience[] = [
                        'company' => $companies[$i] ?? '',
                        'position' => $positions[$i] ?? '',
                        'duration' => $years[$i] ?? '',
                        'description' => $descriptions[$i] ?? ''
                    ];
                }
            }
        }
        // Fallback to applicantlist work_experience if user_profiles is empty
        else if (!empty($applicant['work_experience'])) {
            $workExperience = json_decode($applicant['work_experience'], true);
            if (is_array($workExperience)) {
                foreach ($workExperience as $exp) {
                    $backgroundExperience[] = [
                        'company' => $exp['company'] ?? '',
                        'position' => $exp['position'] ?? '',
                        'duration' => $exp['duration'] ?? '',
                        'description' => $exp['description'] ?? ''
                    ];
                }
            }
        }
        
        // Parse skills data - prioritize user_profiles, fallback to applicantlist
        $skills = [];
        if (!empty($applicant['skills_traits'])) {
            $skills = explode(',', $applicant['skills_traits']);
            $skills = array_map('trim', $skills);
        } else if (!empty($applicant['skills'])) {
            $skillsData = json_decode($applicant['skills'], true);
            if (is_array($skillsData)) {
                $skills = $skillsData;
            }
        }
        
        // Parse education data - prioritize user_profiles, fallback to applicantlist
        $education = [];
        if (!empty($applicant['education_level'])) {
            $education[] = [
                'id' => 1,
                'level' => $applicant['education_level'] ?? '',
                'school' => $applicant['school_name'] ?? '',
                'address' => $applicant['school_address'] ?? '',
                'field' => $applicant['field_study'] ?? '',
                'startYear' => $applicant['start_year'] ?? '',
                'endYear' => $applicant['end_year'] ?? ''
            ];
        } else if (!empty($applicant['al_education'])) {
            $educationData = json_decode($applicant['al_education'], true);
            if (is_array($educationData)) {
                foreach ($educationData as $index => $edu) {
                    $education[] = [
                        'id' => $index + 1,
                        'level' => $edu['level'] ?? '',
                        'school' => $edu['school'] ?? '',
                        'address' => $edu['address'] ?? '',
                        'field' => $edu['field'] ?? '',
                        'startYear' => $edu['startYear'] ?? '',
                        'endYear' => $edu['endYear'] ?? ''
                    ];
                }
            }
        }
        
        // Format profile image URL properly
        $profileImage = '';
        if (!empty($applicant['profile_image'])) {
            if (strpos($applicant['profile_image'], 'http') === 0) {
                $profileImage = $applicant['profile_image'];
            } else {
                $profileImage = 'http://localhost/difsysapi/' . $applicant['profile_image'];
            }
        }
        
        // Format application date to show only date part
        $applicationDate = '';
        if (!empty($applicant['application_date'])) {
            $applicationDate = date('Y-m-d', strtotime($applicant['application_date']));
        }
        
        // FIXED: Use application_date from applicantlist.created_at and position from applicantlist.position
        $response = [
            'basicInfo' => [
                'name' => trim(($applicant['firstName'] ?? '') . ' ' . ($applicant['lastName'] ?? '')),
                'id' => $applicant['id'] ?? '',
                'gender' => $applicant['gender'] ?? '',
                'email' => $applicant['email'] ?? '',
                'contact' => $applicant['contact_number'] ?? '',
                'position' => $applicant['position'] ?? 'N/A', // FIXED: Use position from applicantlist
                'dateHired' => '', // Applicants don't have hire dates
                'applicationDate' => $applicationDate, // FIXED: Format date only
                'workingDays' => '',
                'restDay' => '',
                'workArrangement' => '',
                'profileImage' => $profileImage
            ],
            'backgroundExperience' => $backgroundExperience,
            'skills' => $skills,
            'education' => $education
        ];
        
        echo json_encode($response);
        
    } catch (Exception $e) {
        error_log("Applicant Details Get error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    }
}

function handleApplicantDetailsPost($conn, $input) {
    if (!$input || !isset($input['user_id'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid input data']);
        return;
    }
    
    try {
        $conn->begin_transaction();
        
        $user_id = (int) $input['user_id'];
        
        // Handle background experience data for user_profiles
        $backgroundPositions = [];
        $backgroundCompanies = [];
        $backgroundYears = [];
        $backgroundDescriptions = [];
        
        if (isset($input['backgroundExperience']) && is_array($input['backgroundExperience'])) {
            foreach ($input['backgroundExperience'] as $exp) {
                $backgroundPositions[] = $exp['position'] ?? '';
                $backgroundCompanies[] = $exp['company'] ?? '';
                $backgroundYears[] = $exp['duration'] ?? '';
                $backgroundDescriptions[] = $exp['description'] ?? '';
            }
        }
        
        $backgroundPositionStr = implode('|', $backgroundPositions);
        $backgroundCompanyStr = implode('|', $backgroundCompanies);
        $backgroundYearStr = implode('|', $backgroundYears);
        $backgroundDescriptionStr = implode('|', $backgroundDescriptions);
        
        // Handle skills data for user_profiles
        $skillsStr = '';
        if (isset($input['skills']) && is_array($input['skills'])) {
            $skillsStr = implode(', ', $input['skills']);
        }
        
        // Handle education data for user_profiles (first education entry only)
        $education_level = '';
        $school_name = '';
        $school_address = '';
        $field_study = '';
        $start_year = '';
        $end_year = '';
        
        if (isset($input['education']) && is_array($input['education']) && count($input['education']) > 0) {
            $firstEducation = $input['education'][0];
            $education_level = $firstEducation['level'] ?? '';
            $school_name = $firstEducation['school'] ?? '';
            $school_address = $firstEducation['address'] ?? '';
            $field_study = $firstEducation['field'] ?? '';
            $start_year = $firstEducation['startYear'] ?? '';
            $end_year = $firstEducation['endYear'] ?? '';
        }
        
        // Update or insert into user_profiles
        $checkSql = "SELECT id FROM user_profiles WHERE user_id = ?";
        $checkStmt = $conn->prepare($checkSql);
        $checkStmt->bind_param("i", $user_id);
        $checkStmt->execute();
        $checkResult = $checkStmt->get_result();
        $profileExists = $checkResult->fetch_assoc();
        
        if ($profileExists) {
            // Update existing user_profiles record
            $sql = "UPDATE user_profiles SET 
                        background_position = ?,
                        background_company = ?,
                        background_year = ?,
                        background_description = ?,
                        skills_traits = ?,
                        education_level = ?,
                        school_name = ?,
                        school_address = ?,
                        field_study = ?,
                        start_year = ?,
                        end_year = ?,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE user_id = ?";
            
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                throw new Exception('Prepare failed: ' . $conn->error);
            }
            
            $stmt->bind_param("sssssssssssi",
                $backgroundPositionStr,
                $backgroundCompanyStr,
                $backgroundYearStr,
                $backgroundDescriptionStr,
                $skillsStr,
                $education_level,
                $school_name,
                $school_address,
                $field_study,
                $start_year,
                $end_year,
                $user_id
            );
        } else {
            // Insert new user_profiles record
            $sql = "INSERT INTO user_profiles (
                        user_id, background_position, background_company, 
                        background_year, background_description, skills_traits,
                        education_level, school_name, school_address, field_study, 
                        start_year, end_year
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                throw new Exception('Prepare failed: ' . $conn->error);
            }
            
            $stmt->bind_param("isssssssssss",
                $user_id,
                $backgroundPositionStr,
                $backgroundCompanyStr,
                $backgroundYearStr,
                $backgroundDescriptionStr,
                $skillsStr,
                $education_level,
                $school_name,
                $school_address,
                $field_study,
                $start_year,
                $end_year
            );
        }
        
        if (!$stmt->execute()) {
            throw new Exception('Save failed: ' . $stmt->error);
        }
        
        // Also update applicantlist with JSON data for compatibility
        $workExperienceJson = json_encode($input['backgroundExperience'] ?? []);
        $skillsJson = json_encode($input['skills'] ?? []);
        $educationJson = json_encode($input['education'] ?? []);
        
        $checkApplicantSql = "SELECT id FROM applicantlist WHERE app_id = ?";
        $checkApplicantStmt = $conn->prepare($checkApplicantSql);
        $checkApplicantStmt->bind_param("i", $user_id);
        $checkApplicantStmt->execute();
        $checkApplicantResult = $checkApplicantStmt->get_result();
        $applicantExists = $checkApplicantResult->fetch_assoc();
        
        if ($applicantExists) {
            $updateApplicantSql = "UPDATE applicantlist SET 
                                    work_experience = ?,
                                    skills = ?,
                                    education = ?,
                                    updated_at = CURRENT_TIMESTAMP
                                WHERE app_id = ?";
            $updateApplicantStmt = $conn->prepare($updateApplicantSql);
            $updateApplicantStmt->bind_param("sssi", $workExperienceJson, $skillsJson, $educationJson, $user_id);
            $updateApplicantStmt->execute();
        }
        
        $conn->commit();
        echo json_encode(['success' => true, 'message' => 'Applicant details updated successfully']);
        
    } catch (Exception $e) {
        $conn->rollback();
        error_log("Applicant Details Save error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    }
}

function handleApplicantDetailsPut($conn, $input) {
    // For this implementation, PUT will work the same as POST
    handleApplicantDetailsPost($conn, $input);
}

// EXISTING FUNCTIONS FOR APPLICANT PERSONAL INFO
function handleGet($conn) {
    $user_id = $_GET['user_id'] ?? null;
    
    if (!$user_id) {
        http_response_code(400);
        echo json_encode(['error' => 'User ID is required']);
        return;
    }
    
    try {
        error_log("Searching for applicant user_id: " . $user_id);
        
        // Get data from useraccounts, applicantlist, and user_profiles
        $sql = "SELECT 
                    ua.id,
                    ua.firstName,
                    ua.lastName,
                    ua.email,
                    ua.profile_image,
                    al.middle_name,
                    al.contact_number,
                    al.address,
                    al.date_of_birth,
                    al.civil_status,
                    al.gender,
                    al.citizenship,
                    al.height,
                    al.weight,
                    al.education as al_education,
                    up.place_birth,
                    up.marital_status,
                    up.religion,
                    up.permanent_address,
                    up.emergency_name,
                    up.emergency_rel,
                    up.emergency_number,
                    up.education_level,
                    up.school_name,
                    up.school_address,
                    up.field_study,
                    up.start_year,
                    up.end_year,
                    up.family_type,
                    up.family_name
                FROM useraccounts ua 
                LEFT JOIN applicantlist al ON ua.id = al.app_id
                LEFT JOIN user_profiles up ON ua.id = up.user_id
                WHERE ua.id = ? AND ua.role = 'applicant'";
                
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new Exception('SQL prepare failed: ' . $conn->error);
        }
        
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $profile = $result->fetch_assoc();
        
        error_log("Profile result for applicant user_id $user_id: " . json_encode($profile));
        
        if (!$profile) {
            http_response_code(404);
            echo json_encode(['error' => 'Applicant not found']);
            return;
        }
        
        // Parse education data - prioritize user_profiles, fallback to applicantlist
        $education = [];
        if (!empty($profile['education_level'])) {
            $education[] = [
                'id' => 1,
                'level' => $profile['education_level'] ?? '',
                'school' => $profile['school_name'] ?? '',
                'address' => $profile['school_address'] ?? '',
                'field' => $profile['field_study'] ?? '',
                'startYear' => $profile['start_year'] ?? '',
                'endYear' => $profile['end_year'] ?? ''
            ];
        } else if (!empty($profile['al_education'])) {
            $educationData = json_decode($profile['al_education'], true);
            if (is_array($educationData)) {
                foreach ($educationData as $index => $edu) {
                    $education[] = [
                        'id' => $index + 1,
                        'level' => $edu['level'] ?? '',
                        'school' => $edu['school'] ?? '',
                        'address' => $edu['address'] ?? '',
                        'field' => $edu['field'] ?? '',
                        'startYear' => $edu['startYear'] ?? '',
                        'endYear' => $edu['endYear'] ?? ''
                    ];
                }
            }
        }
        
        // Parse family data from user_profiles
        $family = [];
        if (!empty($profile['family_type'])) {
            $family[] = [
                'id' => 1,
                'type' => $profile['family_type'] ?? '',
                'name' => $profile['family_name'] ?? ''
            ];
        }
        
        // FIXED: Format profile image URL properly
        $profileImage = '';
        if (!empty($profile['profile_image'])) {
            if (strpos($profile['profile_image'], 'http') === 0) {
                $profileImage = $profile['profile_image'];
            } else {
                $profileImage = 'http://localhost/difsysapi/' . $profile['profile_image'];
            }
        }
        
        // Format response data - prioritize user_profiles data, fallback to applicantlist
        $response = [
            'name' => trim(($profile['firstName'] ?? '') . ' ' . ($profile['lastName'] ?? '')),
            'id' => $profile['id'] ?? '',
            'gender' => $profile['gender'] ?? '',
            'email' => $profile['email'] ?? '',
            'contact' => $profile['contact_number'] ?? '',
            'placeOfBirth' => $profile['place_birth'] ?? '',
            'birthDate' => $profile['date_of_birth'] ?? '',
            'maritalStatus' => $profile['marital_status'] ?? $profile['civil_status'] ?? '',
            'religion' => $profile['religion'] ?? '',
            'citizenIdAddress' => $profile['address'] ?? '',
            'residentialAddress' => $profile['permanent_address'] ?? $profile['address'] ?? '',
            'emergencyContactName' => $profile['emergency_name'] ?? '',
            'emergencyContactRelationship' => $profile['emergency_rel'] ?? '',
            'emergencyContactPhone' => $profile['emergency_number'] ?? '',
            'education' => $education,
            'family' => $family,
            'profileImage' => $profileImage  // FIXED: Now properly included
        ];
        
        error_log("Final response: " . json_encode($response));
        
        echo json_encode($response);
        
    } catch (Exception $e) {
        error_log("Database error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    }
}

function handlePost($conn, $input) {
    if (!$input || !isset($input['user_id'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid input data']);
        return;
    }
    
    try {
        $conn->begin_transaction();
        
        $user_id = (int) $input['user_id'];
        $contact = isset($input['contact']) ? (string) $input['contact'] : '';
        $birthDate = isset($input['birthDate']) && !empty($input['birthDate']) ? (string) $input['birthDate'] : null;
        $gender = isset($input['gender']) ? (string) $input['gender'] : '';
        $placeOfBirth = isset($input['placeOfBirth']) ? (string) $input['placeOfBirth'] : '';
        $maritalStatus = isset($input['maritalStatus']) ? (string) $input['maritalStatus'] : '';
        $religion = isset($input['religion']) ? (string) $input['religion'] : '';
        $citizenIdAddress = isset($input['citizenIdAddress']) ? (string) $input['citizenIdAddress'] : '';
        $residentialAddress = isset($input['residentialAddress']) ? (string) $input['residentialAddress'] : '';
        $emergencyContactName = isset($input['emergencyContactName']) ? (string) $input['emergencyContactName'] : '';
        $emergencyContactRelationship = isset($input['emergencyContactRelationship']) ? (string) $input['emergencyContactRelationship'] : '';
        $emergencyContactPhone = isset($input['emergencyContactPhone']) ? (string) $input['emergencyContactPhone'] : '';
        $email = isset($input['email']) ? (string) $input['email'] : '';
        
        // Prepare education data (only first education entry for user_profiles)
        $education_level = '';
        $school_name = '';
        $school_address = '';
        $field_study = '';
        $start_year = '';
        $end_year = '';
        
        if (isset($input['education']) && is_array($input['education']) && count($input['education']) > 0) {
            $firstEducation = $input['education'][0];
            $education_level = isset($firstEducation['level']) ? (string) $firstEducation['level'] : '';
            $school_name = isset($firstEducation['school']) ? (string) $firstEducation['school'] : '';
            $school_address = isset($firstEducation['address']) ? (string) $firstEducation['address'] : '';
            $field_study = isset($firstEducation['field']) ? (string) $firstEducation['field'] : '';
            $start_year = isset($firstEducation['startYear']) ? (string) $firstEducation['startYear'] : '';
            $end_year = isset($firstEducation['endYear']) ? (string) $firstEducation['endYear'] : '';
        }
        
        // Prepare family data (only first family entry for user_profiles)
        $family_type = '';
        $family_name = '';
        
        if (isset($input['family']) && is_array($input['family']) && count($input['family']) > 0) {
            $firstFamily = $input['family'][0];
            $family_type = isset($firstFamily['type']) ? (string) $firstFamily['type'] : '';
            $family_name = isset($firstFamily['name']) ? (string) $firstFamily['name'] : '';
        }
        
        // Update or insert into user_profiles
        $checkSql = "SELECT id FROM user_profiles WHERE user_id = ?";
        $checkStmt = $conn->prepare($checkSql);
        $checkStmt->bind_param("i", $user_id);
        $checkStmt->execute();
        $checkResult = $checkStmt->get_result();
        $profileExists = $checkResult->fetch_assoc();
        
        if ($profileExists) {
            // Update existing user_profiles record
            $sql = "UPDATE user_profiles SET 
                        contact_number = ?,
                        date_of_birth = ?,
                        gender = ?,
                        place_birth = ?,
                        marital_status = ?,
                        religion = ?,
                        address = ?,
                        permanent_address = ?,
                        emergency_name = ?,
                        emergency_rel = ?,
                        emergency_number = ?,
                        education_level = ?,
                        school_name = ?,
                        school_address = ?,
                        field_study = ?,
                        start_year = ?,
                        end_year = ?,
                        family_type = ?,
                        family_name = ?,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE user_id = ?";
            
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                throw new Exception('Prepare failed: ' . $conn->error);
            }
            
            $stmt->bind_param("sssssssssssssssssssi",
                $contact,
                $birthDate,
                $gender,
                $placeOfBirth,
                $maritalStatus,
                $religion,
                $citizenIdAddress,
                $residentialAddress,
                $emergencyContactName,
                $emergencyContactRelationship,
                $emergencyContactPhone,
                $education_level,
                $school_name,
                $school_address,
                $field_study,
                $start_year,
                $end_year,
                $family_type,
                $family_name,
                $user_id
            );
            
            if (!$stmt->execute()) {
                throw new Exception('Update user_profiles failed: ' . $stmt->error);
            }
        } else {
            // Insert new user_profiles record
            $sql = "INSERT INTO user_profiles (
                        user_id, contact_number, date_of_birth, gender, place_birth, 
                        marital_status, religion, address, permanent_address, 
                        emergency_name, emergency_rel, emergency_number,
                        education_level, school_name, school_address, field_study, start_year, end_year,
                        family_type, family_name
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                throw new Exception('Prepare failed: ' . $conn->error);
            }
            
            $stmt->bind_param("isssssssssssssssssss",
                $user_id,
                $contact,
                $birthDate,
                $gender,
                $placeOfBirth,
                $maritalStatus,
                $religion,
                $citizenIdAddress,
                $residentialAddress,
                $emergencyContactName,
                $emergencyContactRelationship,
                $emergencyContactPhone,
                $education_level,
                $school_name,
                $school_address,
                $field_study,
                $start_year,
                $end_year,
                $family_type,
                $family_name
            );
            
            if (!$stmt->execute()) {
                throw new Exception('Insert user_profiles failed: ' . $stmt->error);
            }
        }
        
        // Also sync basic data to applicantlist for compatibility
        $checkApplicantSql = "SELECT id FROM applicantlist WHERE app_id = ?";
        $checkApplicantStmt = $conn->prepare($checkApplicantSql);
        $checkApplicantStmt->bind_param("i", $user_id);
        $checkApplicantStmt->execute();
        $checkApplicantResult = $checkApplicantStmt->get_result();
        $applicantExists = $checkApplicantResult->fetch_assoc();
        
        if ($applicantExists) {
            $updateApplicantSql = "UPDATE applicantlist SET 
                                    contact_number = ?,
                                    date_of_birth = ?,
                                    gender = ?,
                                    civil_status = ?,
                                    address = ?,
                                    updated_at = CURRENT_TIMESTAMP
                                WHERE app_id = ?";
            $updateApplicantStmt = $conn->prepare($updateApplicantSql);
            $updateApplicantStmt->bind_param("sssssi", $contact, $birthDate, $gender, $maritalStatus, $citizenIdAddress, $user_id);
            $updateApplicantStmt->execute();
        }
        
        // Update useraccounts table with email if provided
        if (!empty($email)) {
            $updateUserAccountsSql = "UPDATE useraccounts SET email = ? WHERE id = ?";
            $updateUserAccountsStmt = $conn->prepare($updateUserAccountsSql);
            if (!$updateUserAccountsStmt) {
                throw new Exception('Prepare useraccounts update failed: ' . $conn->error);
            }
            
            $updateUserAccountsStmt->bind_param("si", $email, $user_id);
            if (!$updateUserAccountsStmt->execute()) {
                throw new Exception('Update useraccounts failed: ' . $updateUserAccountsStmt->error);
            }
        }
        
        $conn->commit();
        echo json_encode(['success' => true, 'message' => 'Applicant details updated successfully']);
        
    } catch (Exception $e) {
        $conn->rollback();
        error_log("Save error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    }
}

function handlePut($conn, $input) {
    // For this implementation, PUT will work the same as POST
    handlePost($conn, $input);
}

function handleDelete($conn, $input) {
    if (!$input || !isset($input['user_id'])) {
        http_response_code(400);
        echo json_encode(['error' => 'User ID is required']);
        return;
    }
    
    try {
        $conn->begin_transaction();
        
        $user_id = (int) $input['user_id'];
        
        // Try to clear education and family data in user_profiles if table exists
        try {
            if (isset($input['clear_education']) && $input['clear_education']) {
                $sql = "UPDATE user_profiles SET 
                            education_level = NULL,
                            school_name = NULL,
                            school_address = NULL, 
                            field_study = NULL,
                            start_year = NULL,
                            end_year = NULL
                        WHERE user_id = ?";
                $stmt = $conn->prepare($sql);
                if ($stmt) {
                    $stmt->bind_param("i", $user_id);
                    $stmt->execute();
                }
            }
            
            if (isset($input['clear_family']) && $input['clear_family']) {
                $sql = "UPDATE user_profiles SET 
                            family_type = NULL,
                            family_name = NULL
                        WHERE user_id = ?";
                $stmt = $conn->prepare($sql);
                if ($stmt) {
                    $stmt->bind_param("i", $user_id);
                    $stmt->execute();
                }
            }
            
            if (isset($input['clear_skills']) && $input['clear_skills']) {
                $sql = "UPDATE user_profiles SET 
                            background_position = NULL,
                            background_company = NULL,
                            background_year = NULL,
                            background_description = NULL,
                            skills_traits = NULL
                        WHERE user_id = ?";
                $stmt = $conn->prepare($sql);
                if ($stmt) {
                    $stmt->bind_param("i", $user_id);
                    $stmt->execute();
                }
            }
        } catch (Exception $e) {
            error_log("Could not clear user_profiles data: " . $e->getMessage());
        }
        
        // Also clear corresponding data in applicantlist for consistency
        try {
            if (isset($input['clear_education']) && $input['clear_education']) {
                $sql = "UPDATE applicantlist SET education = NULL WHERE app_id = ?";
                $stmt = $conn->prepare($sql);
                if ($stmt) {
                    $stmt->bind_param("i", $user_id);
                    $stmt->execute();
                }
            }
            
            if (isset($input['clear_skills']) && $input['clear_skills']) {
                $sql = "UPDATE applicantlist SET 
                            work_experience = NULL,
                            skills = NULL
                        WHERE app_id = ?";
                $stmt = $conn->prepare($sql);
                if ($stmt) {
                    $stmt->bind_param("i", $user_id);
                    $stmt->execute();
                }
            }
        } catch (Exception $e) {
            error_log("Could not clear applicantlist data: " . $e->getMessage());
        }
        
        $conn->commit();
        echo json_encode(['success' => true, 'message' => 'Records cleared successfully']);
        
    } catch (Exception $e) {
        $conn->rollback();
        http_response_code(500);
        echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    }
}
?>