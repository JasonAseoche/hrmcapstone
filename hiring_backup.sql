-- MySQL dump 10.13  Distrib 8.4.6, for Linux (x86_64)
--
-- Host: localhost    Database: difsysdb
-- ------------------------------------------------------
-- Server version	8.4.6-0ubuntu0.25.04.1

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!50503 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `hiring_positions`
--

DROP TABLE IF EXISTS `hiring_positions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `hiring_positions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `title` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `short_description` text COLLATE utf8mb4_general_ci,
  `image_path` varchar(500) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `requirements` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin,
  `duties` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin,
  `status` enum('active','inactive','closed') COLLATE utf8mb4_general_ci DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`),
  CONSTRAINT `hiring_positions_chk_1` CHECK (json_valid(`requirements`)),
  CONSTRAINT `hiring_positions_chk_2` CHECK (json_valid(`duties`))
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `hiring_positions`
--

LOCK TABLES `hiring_positions` WRITE;
/*!40000 ALTER TABLE `hiring_positions` DISABLE KEYS */;
INSERT INTO `hiring_positions` VALUES (6,'Automation Engineer ','AKJSLDHAKLSJDALKSJDLAK','uploads/hiring_positions/hire_689099021b262_1754306818.jpg','[\"BS in Electrical Engineering \",\"BS in Eletronics Engineering \"]','[\"Design Automation Systems and Control Panels \",\"Panel Assembly Supervision\"]','inactive','2025-08-04 11:26:58','2025-08-04 11:27:22'),(7,'sdfs','dsdfsfsd','uploads/hiring_positions/hire_6891958d9d304_1754371469.jpg','[\"asda\"]','[\"asdads\"]','inactive','2025-08-05 05:24:29','2025-08-05 16:15:35'),(8,'Electrician','Electrician is a skilled professional installs, maintains, and repairs electrical systems in residential, commercial, and industrial settings. Ensuring that wiring, outlets, lighting, and electrical components meet safety codes and function properly. With expertise in troubleshooting and problem-solving, electricians keep our electrical systems running smoothly and safely.','uploads/hiring_positions/hire_689234ae6ba3d_1754412206.jpg','[\"Male\",\"NC II holder\",\"With Experience as Electrician\",\"Familiar with Environmental, Health and safety guidelines implemented in the industry.\",\"Willing to be assigned to different locations.\"]','[\"Assist Testing and Commissioning.\",\"Cable Pulling.\",\"Control panel assembly and wiring.\",\"Control panel and testing.\",\"Conduct cable pulling.\",\"Install pipe support and bracket.\",\"Termination works.\",\"Electrical conduit installation and assembly.\",\"Troubleshooting of electrical and electronics circuits.\",\"Follow the instructions of engineer.\",\"Flexible in handling different tasks.\",\"Estimation of site materials.\",\"Team player and goal oriented.\",\"Follow company Rules and Policies.\"]','active','2025-08-05 16:43:26','2025-08-05 17:55:21'),(9,'Automation Technician ','Automation Technician is responsible for installing, maintaining, and troubleshooting automated systems and machinery used in manufacturing or production environments. Ensuring that equipment operates efficiently by conducting regular inspections, calibrations, and repairs.','uploads/hiring_positions/hire_689238dd8ee59_1754413277.jpeg','[\"Bachelor\\u2019s Degree in Industrial Technology Major in Electronics Technology\",\"Fresh graduates are welcome.\",\"Ability to read and interpret drawings.\",\"Ability to manage automation projects.\",\"Experience working on-site (testing, commissioning, and troubleshooting.\",\"Familiarity with Environmental, Health, and Safety guidelines implemented in the industry.\",\"Willing to be assigned to different locations.\",\"Available to start immediately.\"]','[\"Design, program, simulate, and assist in testing and commissioning of automation system.\",\"Troubleshoot and maintain automation systems.\",\"Install new automation systems and configure hardware or software components as per the project requirements. \",\"Design, Build, wire and terminate control panels following the insdustrial standards. \",\"Perform onsite control panel and field devices installation and termination.\",\"Assist , Coordinate with Automation engineers.\",\"Document automation processes and maintain detailed records of system configurations, modifications, and performance issues for future reference. \",\"Train end-users and technical staff on the operation and maintenance of automation systems to ensure safe and effective use.\",\"Perform automation systems material estimations.\",\"Follow the instructions of your superior. \",\"Flexible in handling different tasks.\",\"Send daily objectives and accomplishment\\u00a0reports.\",\"All programs to be developed by the automation technician will be DIFSYS INC\\u2019s property and must be turned over to the company.\"]','active','2025-08-05 17:01:17','2025-08-05 17:52:15'),(10,'Automation Engineer','Automation Engineer designs and implements automated systems to improve efficiency and reduce manual labor in various industries. Work with software, hardware, and control systems to streamline processes, increase productivity, and ensure quality. By integrating cutting-edge technology, Automation Engineers help businesses achieve optimal performance and cost savings.\r\n','uploads/hiring_positions/hire_68924169ee014_1754415465.jpeg','[\"BS in Electrical Engineering\",\"BS in Eletronics Engineering\",\"BS in Instrumentation and Control Engineering\",\"Male\",\"Fresh graduate are welcome.\",\"Ability to read and interpret drawings.\",\"Ability to Manage automation projects.\",\"Familiar with Environmental, Health and safety guidelines implemented in the industry.\",\"Willing to be assigned to different locations.\"]','[\"Design Automation Systems and Control Panels.\",\"Panel Assembly Supervision.\",\"Develop Automation Programs.\",\"Execute Instrumentation works, Testing and Commissioning.\",\"Make material requests to the purchasing staff.\",\"Daily updates on the progress of every project.\",\"Make a report for Billing, Project Documentation, Cash advance, and Expenses report.\",\"Troubleshoot equipment and perform complex system tests.\",\"Lead a  team and solve problems.\",\"Communicate well with other members of the team.\",\"Supervise and assign responsibilities to electricians and other workers.\",\"Conduct Site Survey and material Estimation.\",\"Flexible in Handling different projects.\",\"Help and Train the new engineers.\",\"All programs to be developed by the engineer will be DIFSYS INC\\u2019s property and must be turned over to the company.\"]','active','2025-08-05 17:37:45','2025-08-05 17:46:23'),(11,'Welder','Welder is a skilled tradesperson who uses heat, pressure, or filler material to join metals together in various industries. Working with different welding techniques, such as MIG, TIG, and Stick welding, to create strong, durable bonds. Welders must be precise and safety-conscious, as the job often involves working with high temperatures, heavy machinery, and potentially hazardous materials.\r\n','uploads/hiring_positions/hire_689247e8e463f_1754417128.jpeg','[\"Male\",\"NC II holder\",\"With Experience as Electrician.\",\"Familiar with Environmental, Health and safety guidelines implemented in the industry.\",\"Willing to be assigned to different locations.\"]','[\"Interpret blueprints and schematics and MIG or TIG weld parts as defined in specification sheets.\",\"Position, align, and secure parts and assemblies prior to assembly using straightedges, combination squares, callipers, and rulers.\",\"Monitor fitting, burning, and welding processes to avoid overheating, warping, shrinking, distortion, or expansion of material.\",\"Examine completed work for defects and measure pieces with straightedges and templates to ensure conformance with specifications; report nonconforming material to team leader for quality assurance.\",\"Uphold and reinforce standards and procedures outlined in safety manuals and occupational health and safety regulations.\",\"Welding works, Fabrication, and installation of pipe support\\/ brackets.\",\"Preparing Materials for welding works.\",\"Follow the instruction of Engineer.\",\"Flexible in handling different positions.\"]','active','2025-08-05 18:05:28','2025-08-05 18:05:28'),(12,'Helper','Helper assists the lead welder by preparing materials, tools, and work areas, ensuring that all necessary equipment is ready for the welding process. Helper is familiar with industry-specific Environmental, Health, and Safety (EHS) guidelines, helping to maintain a safe work environment. The helper is also flexible and willing to be assigned to different job sites, providing support wherever needed in the field.\r\n','uploads/hiring_positions/hire_689249c4d4a09_1754417604.jpeg','[\"Male\",\"Familiar with Environmental, Health and safety guidelines implemented in the industry.\",\"Willing to be assigned to different locations.\"]','[\"Tool maintenance and upkeep.\",\" Assist with equipment deployment\\/setup Control panel and testing.\",\"Pulling wire.\",\"Measure and cut wires.\",\"Help with conduit work.\",\"Drill Holes for electrical wiring.\",\"Flexible in handling different task.\",\"Follow company rules and policies.\",\"Assist with troubleshooting electrical components.\",\"Assist with wiring diagrams.\",\"Follow the Engineer Instruction.\"]','active','2025-08-05 18:13:24','2025-08-15 04:35:21');
/*!40000 ALTER TABLE `hiring_positions` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2025-08-24  3:29:14
