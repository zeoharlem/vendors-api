#!/bin/bash

# Base path
BASE_DIR="app/Modules/Dispute"

# 1. Create all directories
mkdir -p "$BASE_DIR"/{Domain/{Enums,Entities,Exceptions,Repositories,Contracts,ValueObjects},Application/{Dtos,Services,Validators,UseCases},Infrastructure/{Persistence,Storage,Payments,Notifications},Presentation/{Controllers,Dtos,Routes}}

# 2. Domain: Enums
touch "$BASE_DIR/Domain/Enums/DisputeStatus.php"
touch "$BASE_DIR/Domain/Enums/DisputeResolutionOutcome.php"
touch "$BASE_DIR/Domain/Enums/DisputeEventType.php"

# 3. Domain: Entities
touch "$BASE_DIR/Domain/Entities/Dispute.php"
touch "$BASE_DIR/Domain/Entities/DisputeEvidence.php"
touch "$BASE_DIR/Domain/Entities/DisputeHistory.php"

# 4. Domain: Exceptions
touch "$BASE_DIR/Domain/Exceptions/DisputeNotFoundException.php"
touch "$BASE_DIR/Domain/Exceptions/DisputeAlreadyExistsException.php"
touch "$BASE_DIR/Domain/Exceptions/DisputeAccessDeniedException.php"
touch "$BASE_DIR/Domain/Exceptions/InvalidDisputeTransitionException.php"
touch "$BASE_DIR/Domain/Exceptions/DisputeResolutionException.php"

# 5. Domain: Repositories
touch "$BASE_DIR/Domain/Repositories/DisputeRepositoryInterface.php"
touch "$BASE_DIR/Domain/Repositories/DisputeEvidenceRepositoryInterface.php"
touch "$BASE_DIR/Domain/Repositories/DisputeHistoryRepositoryInterface.php"

# 6. Domain: Contracts
touch "$BASE_DIR/Domain/Contracts/DisputePayableManagerInterface.php"
touch "$BASE_DIR/Domain/Contracts/DisputeRefundManagerInterface.php"
touch "$BASE_DIR/Domain/Contracts/DisputeEvidenceStorageInterface.php"
touch "$BASE_DIR/Domain/Contracts/DisputeNotificationInterface.php"

# 7. Domain: ValueObjects
touch "$BASE_DIR/Domain/ValueObjects/DisputeId.php"
touch "$BASE_DIR/Domain/ValueObjects/DisputeReason.php"
touch "$BASE_DIR/Domain/ValueObjects/EvidenceMetadata.php"

# 8. Application: Dtos
touch "$BASE_DIR/Application/Dtos/OpenDisputeDto.php"
touch "$BASE_DIR/Application/Dtos/RespondToDisputeDto.php"
touch "$BASE_DIR/Application/Dtos/UpdateDisputeStatusDto.php"
touch "$BASE_DIR/Application/Dtos/ResolveDisputeDto.php"
touch "$BASE_DIR/Application/Dtos/CloseDisputeDto.php"
touch "$BASE_DIR/Application/Dtos/UploadDisputeEvidenceDto.php"

# 9. Application: Services
touch "$BASE_DIR/Application/Services/DisputeService.php"
touch "$BASE_DIR/Application/Services/DisputeEvidenceService.php"
touch "$BASE_DIR/Application/Services/DisputeResolutionService.php"

# 10. Application: Validators
touch "$BASE_DIR/Application/Validators/OpenDisputeValidator.php"
touch "$BASE_DIR/Application/Validators/RespondToDisputeValidator.php"
touch "$BASE_DIR/Application/Validators/UpdateDisputeStatusValidator.php"
touch "$BASE_DIR/Application/Validators/ResolveDisputeValidator.php"
touch "$BASE_DIR/Application/Validators/UploadDisputeEvidenceValidator.php"

# 11. Application: UseCases
touch "$BASE_DIR/Application/UseCases/OpenDisputeUseCase.php"
touch "$BASE_DIR/Application/UseCases/RespondToDisputeUseCase.php"
touch "$BASE_DIR/Application/UseCases/ReviewDisputeUseCase.php"
touch "$BASE_DIR/Application/UseCases/ResolveDisputeUseCase.php"
touch "$BASE_DIR/Application/UseCases/CloseDisputeUseCase.php"

# 12. Infrastructure: Persistence, Storage, Payments, Notifications
touch "$BASE_DIR/Infrastructure/Persistence/PdoDisputeRepository.php"
touch "$BASE_DIR/Infrastructure/Persistence/PdoDisputeEvidenceRepository.php"
touch "$BASE_DIR/Infrastructure/Persistence/PdoDisputeHistoryRepository.php"
touch "$BASE_DIR/Infrastructure/Storage/LocalDisputeEvidenceStorage.php"
touch "$BASE_DIR/Infrastructure/Payments/PaymentDisputeRefundManager.php"
touch "$BASE_DIR/Infrastructure/Notifications/DisputeNotificationAdapter.php"

# 13. Presentation: Controllers, Dtos, Routes
touch "$BASE_DIR/Presentation/Controllers/DisputeController.php"
touch "$BASE_DIR/Presentation/Controllers/VendorDisputeController.php"
touch "$BASE_DIR/Presentation/Controllers/AdminDisputeController.php"
touch "$BASE_DIR/Presentation/Dtos/DisputeResponseDto.php"
touch "$BASE_DIR/Presentation/Routes/DisputeRoutes.php"
touch "$BASE_DIR/Presentation/Routes/VendorDisputeRoutes.php"
touch "$BASE_DIR/Presentation/Routes/AdminDisputeRoutes.php"

echo "Dispute module directories and PHP files successfully created!"